'use client';

import { useEffect, useState } from 'react';

interface CountdownTimerProps {
  expiresAt: string;
  onExpire?: () => void;
}

export function CountdownTimer({ expiresAt, onExpire }: CountdownTimerProps) {
  const [remaining, setRemaining] = useState(() =>
    Math.max(0, new Date(expiresAt).getTime() - Date.now()),
  );

  useEffect(() => {
    const interval = setInterval(() => {
      const ms = Math.max(0, new Date(expiresAt).getTime() - Date.now());
      setRemaining(ms);
      if (ms === 0) {
        clearInterval(interval);
        onExpire?.();
      }
    }, 1000);
    return () => clearInterval(interval);
  }, [expiresAt, onExpire]);

  const totalSeconds = Math.floor(remaining / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;

  const isUrgent = remaining < 60_000;

  return (
    <span
      className={`font-mono tabular-nums ${
        isUrgent ? 'text-red-600 font-bold' : 'text-slate-700'
      }`}
    >
      {minutes}:{seconds.toString().padStart(2, '0')}
    </span>
  );
}