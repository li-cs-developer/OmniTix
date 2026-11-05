import { Injectable, Logger } from '@nestjs/common';
import { Server, Socket } from 'socket.io';
import { roomFor } from './ws.dto';
import { BroadcastBatcher } from './broadcast-batcher.service';

/**
 * RoomService maintains the mapping of event occurrences to connected sockets.
 *
 * In a multi-replica deployment, this state would be shared via Redis
 * (using socket.io-redis-adapter). For v1 single-replica dev, in-memory is fine.
 */
@Injectable()
export class RoomService {
  private readonly logger = new Logger(RoomService.name);
  private batcher: BroadcastBatcher | null = null;

  /** occurrenceId → set of socket IDs in that room */
  private readonly rooms = new Map<string, Set<string>>();

  /** socket ID → set of occurrenceIds that socket subscribed to */
  private readonly socketRooms = new Map<string, Set<string>>();

  subscribe(socket: Socket, occurrenceId: string): void {
    const room = roomFor(occurrenceId);

    if (!this.rooms.has(occurrenceId)) {
      this.rooms.set(occurrenceId, new Set());
    }
    this.rooms.get(occurrenceId)!.add(socket.id);

    if (!this.socketRooms.has(socket.id)) {
      this.socketRooms.set(socket.id, new Set());
    }
    this.socketRooms.get(socket.id)!.add(occurrenceId);

    socket.join(room);
    this.logger.debug(
      `socket ${socket.id} joined ${occurrenceId} (viewer count: ${this.viewerCount(occurrenceId)})`,
    );
  }

  unsubscribe(socket: Socket, occurrenceId: string): void {
    const room = roomFor(occurrenceId);

    this.rooms.get(occurrenceId)?.delete(socket.id);
    if (this.rooms.get(occurrenceId)?.size === 0) {
      this.rooms.delete(occurrenceId);
    }

    this.socketRooms.get(socket.id)?.delete(occurrenceId);

    socket.leave(room);
    this.logger.debug(`socket ${socket.id} left ${occurrenceId}`);
  }

  /** Called when a socket disconnects — cleans up all its subscriptions. */
  removeSocket(socket: Socket): void {
    const rooms = this.socketRooms.get(socket.id);
    if (!rooms) return;

    for (const occId of rooms) {
      this.rooms.get(occId)?.delete(socket.id);
      if (this.rooms.get(occId)?.size === 0) {
        this.rooms.delete(occId);
      }
    }
    this.socketRooms.delete(socket.id);
    this.logger.debug(`cleaned up socket ${socket.id}`);
  }

  viewerCount(occurrenceId: string): number {
    return this.rooms.get(occurrenceId)?.size ?? 0;
  }

  /** Return all occurrence IDs a socket is subscribed to. */
  occurrencesFor(socketId: string): string[] {
    return Array.from(this.socketRooms.get(socketId) ?? []);
  }

  /** Wire the batcher after the WS server is available. Called from gateway. */
  attachBatcher(batcher: BroadcastBatcher): void {
    this.batcher = batcher;
  }

  /**
   * Broadcast a message to all subscribers of an event occurrence.
   */
  broadcast(server: Server, occurrenceId: string, message: unknown): void {
    if (this.batcher) {
      this.batcher.enqueue(roomFor(occurrenceId), message);
    } else {
      server.to(roomFor(occurrenceId)).emit('message', message);
    }
  }

  /**
   * Broadcast the current viewer count to all subscribers of an occurrence.
   */
  broadcastViewerCount(server: Server, occurrenceId: string): void {
    const count = this.viewerCount(occurrenceId);
    const message = {
      type: 'viewer.count',
      event_occurrence_id: occurrenceId,
      viewer_count: count,
    };
    if (this.batcher) {
      this.batcher.enqueue(roomFor(occurrenceId), message);
    } else {
      server.to(roomFor(occurrenceId)).emit('message', message);
    }
  }

  /** Total number of active occurrence rooms. */
  roomCount(): number {
    return this.rooms.size;
  }
}