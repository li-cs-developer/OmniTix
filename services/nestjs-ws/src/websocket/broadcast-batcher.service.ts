import { Injectable, Logger, OnModuleDestroy } from '@nestjs/common';
import { Server } from 'socket.io';

interface PendingBatch {
  room: string;
  messages: unknown[];
  timer: NodeJS.Timeout | null;
}

@Injectable()
export class BroadcastBatcher implements OnModuleDestroy {
  private readonly logger = new Logger(BroadcastBatcher.name);
  private readonly flushMs = parseInt(process.env.WS_BATCH_FLUSH_MS ?? '50', 10);
  private readonly maxSize = parseInt(process.env.WS_BATCH_MAX_SIZE ?? '5000', 10);
  private readonly batches = new Map<string, PendingBatch>();
  private dropped = 0;
  private server: Server | null = null;

  /** Called by WsGateway once the Socket.IO server is available. */
  attach(server: Server): void {
    this.server = server;
  }

  enqueue(room: string, message: unknown): void {
    if (!this.server) {
      this.logger.warn('Batcher used before attach(); dropping message');
      return;
    }

    let batch = this.batches.get(room);
    if (!batch) {
      batch = { room, messages: [], timer: null };
      this.batches.set(room, batch);
    }

    if (batch.messages.length >= this.maxSize) {
      batch.messages.shift();
      this.dropped++;
      if (this.dropped % 1000 === 1) {
        this.logger.warn(
          `Batcher dropping messages (queue > ${this.maxSize}). Total dropped: ${this.dropped}`,
        );
      }
    }

    batch.messages.push(message);

    if (!batch.timer) {
      batch.timer = setTimeout(() => this.flush(room), this.flushMs);
    }
  }

  private flush(room: string): void {
    const batch = this.batches.get(room);
    if (!batch) return;
    this.batches.delete(room);

    if (batch.messages.length === 0) return;

    const payload =
      batch.messages.length === 1
        ? batch.messages[0]
        : { type: 'batch', messages: batch.messages };

    this.server?.to(room).emit('message', payload);
  }

  onModuleDestroy(): void {
    for (const b of this.batches.values()) {
      if (b.timer) clearTimeout(b.timer);
    }
    this.batches.clear();
  }
}