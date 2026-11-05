import { Injectable, Logger } from '@nestjs/common';

interface SeatStatesResult {
  sold: string[];
  held: string[];
}

@Injectable()
export class InventoryClientService {
  private readonly logger = new Logger(InventoryClientService.name);

  async getSeatStates(
    occurrenceId: string,
  ): Promise<Array<{ seat_id: string; state: 'available' | 'held' | 'sold' }>> {
    const laravelUrl =
      process.env.LARAVEL_INTERNAL_URL ?? 'http://localhost:8000';
    const url = `${laravelUrl}/api/v1/seats/states?occurrence_id=${encodeURIComponent(occurrenceId)}`;

    try {
      const res = await fetch(url, {
        method: 'GET',
        headers: { Accept: 'application/json' },
      });

      if (!res.ok) {
        this.logger.warn(
          `Laravel seat-state lookup returned ${res.status} for ${occurrenceId}`,
        );
        return [];
      }

      const json = (await res.json()) as { data?: SeatStatesResult };
      const data = json.data ?? { sold: [], held: [] };

      // Defensive: some backends return null instead of [] for empty arrays.
      const heldList = Array.isArray(data.held) ? data.held : [];
      const soldList = Array.isArray(data.sold) ? data.sold : [];

      const seats: Array<{
        seat_id: string;
        state: 'held' | 'sold';
      }> = [];

      for (const seatId of heldList) {
        seats.push({ seat_id: seatId, state: 'held' });
      }
      for (const seatId of soldList) {
        seats.push({ seat_id: seatId, state: 'sold' });
      }

      this.logger.debug(
        `getSeatStates(${occurrenceId}) → ${seats.length} seats (${heldList.length} held, ${soldList.length} sold)`,
      );

      return seats;
    } catch (err) {
      this.logger.error(
        `Failed to fetch seat states from Laravel: ${err instanceof Error ? err.message : err}`,
      );
      return [];
    }
  }
}