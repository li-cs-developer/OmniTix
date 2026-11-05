import { Module } from '@nestjs/common';
import { WsGateway } from './ws.gateway';
import { RoomService } from './room.service';
import { BroadcastBatcher } from './broadcast-batcher.service';
import { InventoryModule } from '../inventory/inventory.module';

@Module({
  imports: [InventoryModule],
  providers: [WsGateway, RoomService, BroadcastBatcher],
  exports: [WsGateway, RoomService],
})
export class WsModule {}