import { NestFactory } from '@nestjs/core';
import { IoAdapter } from '@nestjs/platform-socket.io';
import { Logger } from '@nestjs/common';
import { AppModule } from './app.module';
import { RedisIoAdapter } from './websocket/redis-io.adapter';

async function bootstrap() {
  const app = await NestFactory.create(AppModule, {
    logger: ['error', 'warn', 'log', 'debug'],
  });

  // Socket.IO adapter backed by Redis (fan-out across replicas).
  // Falls back to in-memory IoAdapter if Redis is unreachable, which
  // keeps single-pod dev working without Redis.
  const wsAdapter = new RedisIoAdapter(app);
  const logger = new Logger('Bootstrap');

  try {
    await wsAdapter.connectToRedis();
    app.useWebSocketAdapter(wsAdapter);
  } catch (err) {
    logger.warn(
      `Redis adapter unavailable (single-pod mode): ${
        err instanceof Error ? err.message : err
      }`,
    );
    app.useWebSocketAdapter(new IoAdapter(app));
    logger.warn('Using in-memory Socket.IO adapter');
  }

  // Enable CORS for the storefront origin
  app.enableCors({
    origin: process.env.WS_CORS_ORIGIN?.split(',') ?? [
      'http://localhost:3000',
    ],
    credentials: true,
  });

  const port = parseInt(process.env.WS_PORT ?? '3001', 10);
  await app.listen(port);

  logger.log(`OmniTix WS Gateway listening on port ${port}`);
  logger.log(`WebSocket endpoint at ws://localhost:${port}/ws`);
  logger.log(`HTTP health at http://localhost:${port}/health`);
}

bootstrap().catch((err) => {
  console.error('Fatal bootstrap error:', err);
  process.exit(1);
});