import {
  WebSocketGateway,
  WebSocketServer,
  SubscribeMessage,
  OnGatewayConnection,
  OnGatewayDisconnect,
  OnGatewayInit,
  ConnectedSocket,
  MessageBody,
} from '@nestjs/websockets';
import { Logger } from '@nestjs/common';
import { Server, Socket } from 'socket.io';
import { RoomService } from './room.service';
import { BroadcastBatcher } from './broadcast-batcher.service';
import {
  ClientMessage,
  ServerMessage,
  ServerSnapshot,
  roomFor,
} from './ws.dto';
import { InventoryClientService } from '../inventory/inventory-client.service';

@WebSocketGateway({
  path: '/ws',
  cors: { origin: '*', credentials: true },
  // Long timeout to keep idle connections alive
  pingInterval: 25000,
  pingTimeout: 30000,
})
export class WsGateway implements OnGatewayInit, OnGatewayConnection, OnGatewayDisconnect {
  private readonly logger = new Logger(WsGateway.name);

  @WebSocketServer()
  server!: Server;

  constructor(
    private readonly rooms: RoomService,
    private readonly inventory: InventoryClientService,
    private readonly batcher: BroadcastBatcher,
  ) {}

  afterInit(server: Server): void {
    this.batcher.attach(server);
    this.rooms.attachBatcher(this.batcher);
  }

  // ─── Lifecycle ─────────────────────────────────────────

  async handleConnection(socket: Socket): Promise<void> {
    this.logger.log(`client connected: ${socket.id} from ${socket.handshake.address}`);
    // Connection is passive until the client sends a subscribe message.
  }

  handleDisconnect(socket: Socket): void {
    this.logger.log(`client disconnected: ${socket.id}`);
    const occurrences = this.rooms.occurrencesFor(socket.id);
    this.rooms.removeSocket(socket);
    for (const occId of occurrences) {
      this.rooms.broadcastViewerCount(this.server, occId);
    }
  }

  // ─── Messages ──────────────────────────────────────────

  @SubscribeMessage('message')
  async handleMessage(
    @ConnectedSocket() socket: Socket,
    @MessageBody() message: ClientMessage,
  ): Promise<void> {
    try {
      switch (message.type) {
        case 'subscribe':
          await this.onSubscribe(socket, message.event_occurrence_id);
          break;

        case 'unsubscribe':
          this.onUnsubscribe(socket, message.event_occurrence_id);
          break;

        case 'ping':
          this.send(socket, {
            type: 'pong',
            server_sent_at: new Date().toISOString(),
            client_sent_at: message.sent_at,
          });
          break;

        default:
          this.send(socket, {
            type: 'error',
            code: 'unknown_message_type',
            message: `Unknown message type`,
          });
      }
    } catch (err) {
      this.logger.error(`error handling message`, err);
      this.send(socket, {
        type: 'error',
        code: 'internal_error',
        message: err instanceof Error ? err.message : String(err),
      });
    }
  }

  // ─── Subscription ──────────────────────────────────────

  private async onSubscribe(
    socket: Socket,
    occurrenceId: string,
  ): Promise<void> {
    if (!occurrenceId) {
      this.send(socket, {
        type: 'error',
        code: 'missing_occurrence_id',
        message: 'subscribe requires event_occurrence_id',
      });
      return;
    }

    this.rooms.subscribe(socket, occurrenceId);
    this.logger.log(
      `socket ${socket.id} subscribed to ${occurrenceId} (viewers: ${this.rooms.viewerCount(occurrenceId)})`,
    );

    // Fetch initial snapshot from Go inventory service
    let seats: ServerSnapshot['seats'] = [];
    try {
      seats = await this.inventory.getSeatStates(occurrenceId);
    } catch (err) {
      this.logger.warn(
        `failed to fetch snapshot for ${occurrenceId}: ${err instanceof Error ? err.message : err}`,
      );
    }

    this.send(socket, {
      type: 'snapshot',
      event_occurrence_id: occurrenceId,
      seats,
      viewer_count: this.rooms.viewerCount(occurrenceId),
      snapshot_at: new Date().toISOString(),
    });

    // Notify others that viewer count changed
    this.rooms.broadcastViewerCount(this.server, occurrenceId);
  }

  private onUnsubscribe(socket: Socket, occurrenceId: string): void {
    this.rooms.unsubscribe(socket, occurrenceId);
    this.rooms.broadcastViewerCount(this.server, occurrenceId);
  }

  // ─── Public API for other modules (Kafka handlers) ─────

  /** Called by the Kafka event handler when a seat event arrives. */
  broadcastToRoom(occurrenceId: string, message: ServerMessage): void {
    this.rooms.broadcast(this.server, occurrenceId, message);
  }

  private send(socket: Socket, message: ServerMessage): void {
    socket.emit('message', message);
  }
}