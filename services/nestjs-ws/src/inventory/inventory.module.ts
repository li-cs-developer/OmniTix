import { Module } from '@nestjs/common';
import { InventoryClientService } from './inventory-client.service';

@Module({
  providers: [InventoryClientService],
  exports: [InventoryClientService],
})
export class InventoryModule {}