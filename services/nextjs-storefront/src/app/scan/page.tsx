'use client';

import { useEffect, useRef, useState } from 'react';

interface ScanResult {
  result: 'accepted' | 'already_used' | 'invalid_sig' | 'voided' | 'wrong_event' | 'expired' | 'unknown';
  reason: string | null;
  message: string;
  ticket_number?: string;
  attendee_name?: string;
  seat_label?: string;
}

type Status = 'idle' | 'scanning' | 'ok' | 'warn' | 'error';

export default function ScannerPage() {
  const [status, setStatus] = useState<Status>('idle');
  const [last, setLast] = useState<ScanResult | null>(null);
  const [gate, setGate] = useState('Gate A');
  const audioCtxRef = useRef<AudioContext | null>(null);

  useEffect(() => {
    setGate(localStorage.getItem('scan_gate') ?? 'Gate A');
  }, []);

  function beep(freq: number, ms: number) {
    try {
      const Ctx = window.AudioContext ?? (window as any).webkitAudioContext;
      if (!audioCtxRef.current) audioCtxRef.current = new Ctx();
      const ctx = audioCtxRef.current!;
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.frequency.value = freq;
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      gain.gain.setValueAtTime(0.3, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + ms / 1000);
      osc.stop(ctx.currentTime + ms / 1000);
    } catch {}
  }

  async function submitQr(qr: string) {
    setStatus('scanning');
    try {
      const res = await fetch('/api/laravel/v1/scanner/scan', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Occurrence-Id': localStorage.getItem('scan_occurrence') ?? '',
        },
        body: JSON.stringify({
          qr_string: qr,
          gate_identifier: gate,
        }),
      });
      const json = await res.json();
      const data: ScanResult = json.data;
      setLast(data);

      if (data.result === 'accepted') {
        setStatus('ok');
        beep(880, 150);
      } else if (data.result === 'already_used') {
        setStatus('warn');
        beep(440, 400);
      } else {
        setStatus('error');
        beep(220, 600);
      }
    } catch (err: any) {
      setStatus('error');
      setLast({ result: 'unknown', reason: 'network', message: err.message });
      beep(220, 600);
    } finally {
      setTimeout(() => setStatus('idle'), 3000);
    }
  }

  return (
    <div className="min-h-screen bg-slate-900 text-white p-4">
      <div
        className={`rounded-2xl p-8 mb-4 text-center transition-colors ${
          status === 'ok' ? 'bg-green-600'
          : status === 'warn' ? 'bg-amber-500'
          : status === 'error' ? 'bg-red-600'
          : status === 'scanning' ? 'bg-blue-600'
          : 'bg-slate-700'
        }`}
      >
        <div className="text-6xl font-bold uppercase">
          {status === 'ok' ? '✓ VALID'
           : status === 'warn' ? '⚠ USED'
           : status === 'error' ? '✗ REJECT'
           : status === 'scanning' ? '…'
           : 'READY'}
        </div>
        {last && <p className="mt-2 text-lg">{last.message}</p>}
      </div>

      <input
        type="text"
        placeholder="Scan QR or paste qr_string"
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            const v = (e.target as HTMLInputElement).value.trim();
            if (v) submitQr(v);
            (e.target as HTMLInputElement).value = '';
          }
        }}
        className="w-full rounded bg-slate-800 p-4 text-lg mb-4"
      />

      <div className="text-sm space-y-2">
        <label className="block">
          Gate:
          <input
            value={gate}
            onChange={(e) => { setGate(e.target.value); localStorage.setItem('scan_gate', e.target.value); }}
            className="ml-2 rounded bg-slate-800 px-2 py-1"
          />
        </label>
        <label className="block">
          Occurrence (dev):
          <input
            placeholder="01H..."
            onChange={(e) => localStorage.setItem('scan_occurrence', e.target.value)}
            className="ml-2 rounded bg-slate-800 px-2 py-1"
          />
        </label>
      </div>

      {last?.ticket_number && (
        <div className="mt-4 rounded bg-slate-800 p-4 text-sm">
          <p><strong>Ticket:</strong> {last.ticket_number}</p>
          {last.seat_label && <p><strong>Seat:</strong> {last.seat_label}</p>}
          {last.attendee_name && <p><strong>Attendee:</strong> {last.attendee_name}</p>}
        </div>
      )}
    </div>
  );
}