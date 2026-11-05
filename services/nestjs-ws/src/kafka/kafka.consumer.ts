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
    // 1. Grab the prefix from env (defaults to empty string)
    const prefix = process.env.KAFKA_TOPIC_PREFIX ?? '';

    // 2. Define the base topics
    const baseTopics = [
      'seat.held',
      'seat.expired',
      'seat.sold',
      'ga.held',
      'ga.expired',
      'ga.sold',
      'ticket.issued',
      'ticket.scanned',
    ];

    // 3. Apply the prefix if it exists
    const topics = baseTopics.map(t => (prefix ? `${prefix}.${t}` : t));

    await this.consumer.connect();
    this.logger.log(`kafka consumer connected (group: ${process.env.KAFKA_GROUP_ID})`);

    // Retry logic to handle KRaft metadata propagation delay
    for (const topic of topics) {
      let retries = 5;
      while (retries > 0) {
        try {
          await this.consumer.subscribe({ topic, fromBeginning: false });
          break; // Success, move to next topic
        } catch (error: any) {
          if (error.code === 3 && retries > 1) { // UNKNOWN_TOPIC_OR_PARTITION
            this.logger.warn(`Topic ${topic} not ready, retrying in 2s...`);
            await new Promise(resolve => setTimeout(resolve, 2000));
            retries--;
          } else {
            throw error; // Fatal error or out of retries
          }
        }
      }
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