import { IoAdapter } from '@nestjs/platform-socket.io';
import { ServerOptions } from 'socket.io';
import { createAdapter } from '@socket.io/redis-adapter';
import { createClient } from 'redis';
import { INestApplicationContext, Logger } from '@nestjs/common';

/**
 * Socket.IO adapter backed by Redis Pub/Sub.
 *
 * Any broadcast emitted on pod A is received by every pod's adapter and
 * re-emitted to its local sockets. This is what makes RoomService's
 * in-memory maps safe to hold per-process: presence counting is per-pod
 * but fan-out is global.
 *
 * Redis keys use the `socket.io:` prefix (default from @socket.io/redis-adapter)
 * and Redis DB 2 (presence) — separate from Laravel's cache DB 1.
 */
export class RedisIoAdapter extends IoAdapter {
  private readonly logger = new Logger(RedisIoAdapter.name);
  private adapterConstructor: ReturnType<typeof createAdapter> | null = null;

  constructor(private app: INestApplicationContext) {
    super(app);
  }

  async connectToRedis(): Promise<void> {
    const host = process.env.REDIS_HOST ?? '127.0.0.1';
    const port = parseInt(process.env.REDIS_PORT ?? '6380', 10);
    const db = parseInt(process.env.REDIS_DB ?? '2', 10);
    const password = process.env.REDIS_PASSWORD || undefined;

    const opts = { socket: { host, port }, database: db, password } as const;

    const pubClient = createClient(opts);
    const subClient = pubClient.duplicate();

    pubClient.on('error', (err) => this.logger.error('redis pubClient error', err));
    subClient.on('error', (err) => this.logger.error('redis subClient error', err));

    await Promise.all([pubClient.connect(), subClient.connect()]);

    this.adapterConstructor = createAdapter(pubClient, subClient);
    this.logger.log(`Redis adapter connected (${host}:${port} db=${db})`);
  }

  createIOServer(port: number, options?: ServerOptions): any {
    const server = super.createIOServer(port, options);
    if (this.adapterConstructor) {
      server.adapter(this.adapterConstructor);
    }
    return server;
  }
}