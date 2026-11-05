import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { WsModule } from './websocket/ws.module';
import { KafkaModule } from './kafka/kafka.module';
import { HealthModule } from './health/health.module';

@Module({
  imports: [
    ConfigModule.forRoot({
      isGlobal: true,
      envFilePath: ['.env.local', '.env'],
    }),
    WsModule,
    KafkaModule,
    HealthModule,
  ],
})
export class AppModule {}