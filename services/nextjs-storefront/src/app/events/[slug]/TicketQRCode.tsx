'use client';

import { QRCodeSVG } from 'qrcode.react';

interface TicketQRCodeProps {
  qrPayload: string;
  qrSignature: string;
  signerKeyId: string;
  ticketNumber: string;
  size?: number;
}

export function TicketQRCode({
  qrPayload,
  qrSignature,
  signerKeyId,
  ticketNumber,
  size = 180,
}: TicketQRCodeProps) {
  // Reconstruct the full QR string that the Rust service signs:
  // <payload_b64>.<signature_b64>.<key_id>
  const qrString = `${qrPayload}.${qrSignature}.${signerKeyId}`;

  return (
    <div className="flex flex-col items-center gap-2 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700">
      <QRCodeSVG
        value={qrString}
        size={size}
        level="M"
        includeMargin
      />
      <p className="font-mono text-xs text-slate-500">{ticketNumber}</p>
    </div>
  );
}