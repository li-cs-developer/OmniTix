import type { SeatState } from '@/lib/ws';

/**
 * A single seat as it exists in the venue layout.
 * Coordinates come directly from the database — no transformation.
 */
export interface SeatLayoutEntry {
  id: string;
  section_code: string;
  row: string;
  number: string;
  label: string;
  x: number;
  y: number;
  rotation: number;
  attributes: Record<string, unknown>;
}

export interface SectionEntry {
  id: string;
  code: string;
  name: string;
  kind: 'seated' | 'ga' | 'box' | 'table' | 'accessible';
  visual: { color?: string } & Record<string, unknown>;
}

export interface GaZoneEntry {
  id: string;
  code: string;
  name: string;
  capacity: number;
  visual: Record<string, unknown>;
}

export interface SeatMapLayout {
  seat_map_id: string;
  width: number;
  height: number;
  sections: SectionEntry[];
  seats: SeatLayoutEntry[];
  ga_zones: GaZoneEntry[];
}

/**
 * Props for any seat map renderer (SVG today, Canvas in v2).
 * Renderers are pure: given these props, they return a visual.
 * All state lives in the parent <SeatMap />.
 */
export interface SeatMapRendererProps {
  layout: SeatMapLayout;
  seatStates: Record<string, SeatState>;
  selectedSeatIds: Set<string>;
  highlightSeatId: string | null;
  onSeatClick: (seat: SeatLayoutEntry) => void;
  onSeatHover: (seat: SeatLayoutEntry | null, event?: React.MouseEvent) => void;
  onGaZoneClick: (zone: GaZoneEntry) => void;
}

/**
 * Colors matching our Tailwind config and Go/Rust seat states.
 */
export const SEAT_COLORS: Record<SeatState | 'selected' | 'default', string> = {
  available: '#6c5ce7',
  held: '#fdcb6e',
  sold: '#636e72',
  blocked: '#2d3436',
  unavailable: '#b2bec3',
  selected: '#00b894',
  default: '#a29bfe',
};