import {
  Injectable,
  Logger,
  OnModuleInit,
  OnModuleDestroy,
} from '@nestjs/common';
import { Kafka, Consumer, EachMessagePayload } from 'kafkajs';
import { EventHandlerService } from './event.handler';

@Injectable()
export class KafkaConsumerService implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(KafkaConsumerService.name);
  private readonly kafka: Kafka;
  private readonly consumer: Consumer;

  constructor(private readonly handler: EventHandlerService) {
    const brokers = (process.env.KAFKA_BROKERS ?? '127.0.0.1:29092').split(',');
    const groupId = process.env.KAFKA_GROUP_ID ?? 'omnitix-realtime-ws';
    const clientId = process.env.KAFKA_CLIENT_ID ?? 'omnitix-nestjs-ws';

    this.kafka = new Kafka({
      clientId,
      brokers,
      logLevel: 1, // error only
    });

    this.consumer = this.kafka.consumer({
      groupId,
      sessionTimeout: 30000,
      heartbeatInterval: 3000,
    });
  }

  async onModuleInit(): Promise<void> {
    const topics = [
      'omnitix.seat.held',
      'omnitix.seat.expired',
      'omnitix.seat.sold',
      'omnitix.ga.held',
      'omnitix.ga.expired',
      'omnitix.ga.sold',
      'omnitix.ticket.issued',
      'omnitix.ticket.scanned',
    ];

    await this.consumer.connect();
    this.logger.log(`kafka consumer connected (group: ${process.env.KAFKA_GROUP_ID})`);

    for (const topic of topics) {
      await this.consumer.subscribe({ topic, fromBeginning: false });
    }
    this.logger.log(`subscribed to ${topics.length} topics`);

    await this.consumer.run({
      eachMessage: async (payload) => this.handleMessage(payload),
    });
    this.logger.log('kafka consumer running');
  }

  async onModuleDestroy(): Promise<void> {
    this.logger.log('disconnecting kafka consumer...');
    await this.consumer.disconnect();
    this.logger.log('kafka consumer disconnected');
  }

  private async handleMessage(payload: EachMessagePayload): Promise<void> {
    const { topic, partition, message } = payload;
    try {
      const raw = message.value?.toString('utf-8');
      if (!raw) {
        this.logger.warn(`empty message on ${topic}`);
        return;
      }

      const envelope = JSON.parse(raw);
      await this.handler.handle(topic, envelope, partition);
    } catch (err) {
      this.logger.error(
        `failed to handle message on ${topic}: ${err instanceof Error ? err.message : err}`,
      );
    }
  }
}