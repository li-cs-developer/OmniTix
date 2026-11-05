import { Module } from '@nestjs/common';
import { KafkaConsumerService } from './kafka.consumer';
import { EventHandlerService } from './event.handler';
import { WsModule } from '../websocket/ws.module';

@Module({
  imports: [WsModule],
  providers: [KafkaConsumerService, EventHandlerService],
})
export class KafkaModule {}