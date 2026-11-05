<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScannerController extends Controller
{
    /**
     * POST /api/v1/scanner/scan
     *
     * Body: { qr_string, gate_identifier, scan_context? }
     *
     * Flow:
     *   1. Parse qr_string → payload_b64 / sig_b64 / key_id
     *   2. Verify signature with Rust Crypto
     *   3. Look up ticket (by payload's ticket_id, checked against DB row)
     *   4. Reject if: refunded, wrong occurrence, wrong event, already scanned
     *   5. Write ticket_scans row + mark ticket scanned (idempotent per gate)
     *   6. Emit ticket.scanned to Kafka
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_string'      => 'required|string',
            'gate_identifier' => 'required|string|max:64',
            'scan_context'   => 'nullable|array',
        ]);

        $correlationId = (string) \Illuminate\Support\Str::uuid();
        $qrString      = $validated['qr_string'];
        $gate          = $validated['gate_identifier'];

        // ─── 1. Split QR: <payload>.<sig>.<key_id> ────────────
        $parts = explode('.', $qrString);
        if (count($parts) !== 3) {
            return $this->reject('unknown', 'malformed_qr', 'QR string must have 3 dot-separated parts.', null);
        }
        [$payloadB64, $sigB64, $keyId] = $parts;

        // ─── 2. Verify signature with Rust ─────────────────────
        $cryptoUrl = env('CRYPTO_HTTP_URL', 'http://host.docker.internal:50054');
        try {
            $verifyResp = Http::timeout(3)->post("{$cryptoUrl}/api/v1/verify", [
                'qr_string' => $qrString,
            ]);
            $verify = $verifyResp->json();
        } catch (\Throwable $e) {
            Log::error('Scanner: crypto unreachable', ['error' => $e->getMessage()]);
            return $this->reject('unknown', 'crypto_unreachable', $e->getMessage(), null);
        }

        if (! ($verify['valid'] ?? false)) {
            $reason = $verify['failure_reason'] ?? 'invalid_sig';
            Log::warning('Scanner: signature invalid', ['reason' => $reason]);
            return $this->reject('invalid_sig', $reason, 'Signature verification failed.', null);
        }

        // ─── 3. Decode payload, look up ticket ─────────────────
        $payloadBytes = base64_decode(strtr($payloadB64, '-_', '+/'), true);
        $payload = $payloadBytes ? json_decode($payloadBytes, true) : null;
        if (! is_array($payload) || empty($payload['t'])) {
            return $this->reject('unknown', 'payload_unreadable', 'Verified payload unreadable.', null);
        }

        $ticketId = $payload['t'];
        $ticket = Ticket::with(['event', 'occurrence'])->find($ticketId);
        if (! $ticket) {
            return $this->reject('unknown', 'ticket_not_found', "Ticket {$ticketId} not found.", $ticketId);
        }

        // ─── 4. Business-state checks (run inside a DB transaction
        //         with row lock so two gates hitting simultaneously
        //         can't both see "issued") ─────────────────────
        $outcome = DB::transaction(function () use ($ticketId, $gate, $validated, $correlationId) {
            /** @var Ticket $ticket */
            $ticket = Ticket::where('id', $ticketId)->lockForUpdate()->first();

            if (! $ticket) {
                return ['result' => 'unknown', 'reason' => 'ticket_not_found', 'msg' => 'Ticket not found.'];
            }

            if ($ticket->is_refunded || $ticket->status === 'voided') {
                return ['result' => 'voided', 'reason' => 'ticket_voided', 'msg' => 'Ticket has been refunded.'];
            }

            // Wrong occurrence check: the gate is scoped to an occurrence.
            $gateOccurrence = request()->header('X-Occurrence-Id')
                ?? ($validated['scan_context']['event_occurrence_id'] ?? null);
            if ($gateOccurrence && $ticket->event_occurrence_id !== $gateOccurrence) {
                return [
                    'result' => 'wrong_event',
                    'reason' => 'wrong_occurrence',
                    'msg' => 'Ticket belongs to a different occurrence.',
                ];
            }

            if ($ticket->status === 'scanned') {
                // Idempotency: same gate, same ticket → success (allows
                // re-scan without paging an error). Different gate → conflict.
                $lastScan = $ticket->scans()->latest('scanned_at')->first();
                if ($lastScan && $lastScan->gate_identifier === $gate) {
                    return [
                        'result'  => 'already_used',
                        'reason'  => 'same_gate_rescan',
                        'msg'     => 'Already scanned at this gate.',
                        'ticket'  => $ticket,
                        'noop'    => true,
                    ];
                }
                return [
                    'result' => 'already_used',
                    'reason' => 'already_scanned_elsewhere',
                    'msg' => "Already scanned at {$lastScan?->gate_identifier}.",
                    'ticket' => $ticket,
                ];
            }

            if (! in_array($ticket->status, ['issued', 'pending'], true)) {
                return [
                    'result' => 'unknown',
                    'reason' => 'invalid_status',
                    'msg' => "Ticket status '{$ticket->status}' not scannable.",
                    'ticket' => $ticket,
                ];
            }

            // Record the scan
            TicketScan::create([
                'ticket_id'           => $ticket->id,
                'scanned_by_user_id'  => null, // could be set from Sanctum user in admin context
                'gate_identifier'     => $gate,
                'result'              => 'accepted',
                'scanner_ip'          => request()->ip(),
                'scanner_user_agent'  => substr((string) request()->userAgent(), 0, 500),
                'context'             => $validated['scan_context'] ?? [],
                'scanned_at'          => now(),
            ]);

            $ticket->update([
                'status'       => 'scanned',
                'scanned_at'   => now(),
                'scan_count'   => $ticket->scan_count + 1,
            ]);

            return [
                'result' => 'accepted',
                'reason' => null,
                'msg' => 'OK',
                'ticket' => $ticket->fresh(),
            ];
        });

        // ─── 5. Emit ticket.scanned (best-effort) ─────────────
        try {
            $this->emitScanned($outcome, $ticket ?? null, $gate, $correlationId);
        } catch (\Throwable $e) {
            Log::warning('Scanner: kafka emit failed', ['error' => $e->getMessage()]);
        }

        // ─── 6. Build response ─────────────────────────────────
        $code = match ($outcome['result']) {
            'accepted'     => 200,
            'already_used' => 200, // spec: "first wins, second flagged"; 200 with flag
            'voided', 'invalid_sig', 'wrong_event' => 409,
            'expired'      => 410,
            default        => 400,
        };

        return response()->json([
            'data' => [
                'result'         => $outcome['result'],
                'reason'         => $outcome['reason'],
                'message'        => $outcome['msg'],
                'ticket_id'      => $ticket?->id,
                'ticket_number'  => $ticket?->ticket_number,
                'seat_label'     => $ticket?->seat?->display_label,
                'attendee_name'  => $ticket?->attendee_name,
                'scan_result'    => $outcome['result'],
            ],
        ], $code);
    }

    /**
     * GET /api/v1/scanner/health
     */
    public function health(): JsonResponse
    {
        $cryptoUrl = env('CRYPTO_HTTP_URL', 'http://host.docker.internal:50054');
        $cryptoOk = false;
        try {
            $cryptoOk = Http::timeout(1)->get("{$cryptoUrl}/api/v1/health")->ok();
        } catch (\Throwable) {}

        return response()->json([
            'status' => 'ok',
            'crypto_reachable' => $cryptoOk,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function reject(string $result, string $reason, string $message, ?string $ticketId): JsonResponse
    {
        return response()->json([
            'data' => [
                'result'  => $result,
                'reason'  => $reason,
                'message' => $message,
                'ticket_id' => $ticketId,
            ],
        ], $result === 'already_used' ? 200 : 409);
    }

    private function emitScanned(array $outcome, ?Ticket $ticket, string $gate, string $correlationId): void
    {
        if (! $ticket) return;

        $envelope = [
            'id'             => (string) \Illuminate\Support\Str::ulid(),
            'type'           => 'ticket.scanned',
            'version'        => 1,
            'occurred_at'    => now()->toIso8601String(),
            'producer'       => 'laravel-core',
            'correlation_id' => $correlationId,
            'partition_key'  => $ticket->id,
            'payload' => [
                'ticket_id'           => $ticket->id,
                'ticket_number'       => $ticket->ticket_number,
                'event_occurrence_id' => $ticket->event_occurrence_id,
                'gate_identifier'     => $gate,
                'scan_result'         => $outcome['result'],
                'scanned_at'          => now()->toIso8601String(),
                'scanner_ip'          => request()->ip(),
            ],
        ];

        $this->kafkaProducer()->send(
            'omnitix.ticket.scanned',
            $ticket->id,
            json_encode($envelope, JSON_UNESCAPED_SLASHES),
        );
    }

    private function kafkaProducer(): \Illuminate\Support\Facades\Kafka
    {
        // Uses the same Spring-Kafka-compatible producer configured in
        // config/kafka.php (see P1-5 setup). Falls back to a no-op if
        // unavailable so scanning still works when Kafka is down.
        return app(\Illuminate\Support\Facades\Kafka::class);
    }
}