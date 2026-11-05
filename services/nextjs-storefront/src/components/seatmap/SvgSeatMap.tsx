'use client';

import { memo } from 'react';
import {
  SEAT_COLORS,
  type GaZoneEntry,
  type SeatLayoutEntry,
  type SeatMapRendererProps,
} from './types';

const SEAT_RADIUS = 8;

/**
 * SVG renderer for the seat map.
 *
 * Pure component — given the same props, returns the same SVG.
 * For 400 seats this is fast enough; swap to <CanvasSeatMap /> for
 * venues above ~2,000 seats.
 */
export const SvgSeatMap = memo(function SvgSeatMap({
  layout,
  seatStates,
  selectedSeatIds,
  highlightSeatId,
  onSeatClick,
  onSeatHover,
  onGaZoneClick,
}: SeatMapRendererProps) {
  // Index sections by code for O(1) lookups.
  const sectionsByCode = new Map(layout.sections.map((s) => [s.code, s]));

  return (
    <svg
      viewBox={`0 0 ${layout.width} ${layout.height}`}
      className="block w-full h-auto"
      role="img"
      aria-label={`Seat map for ${layout.seats.length} seats`}
      style={{ maxHeight: '70vh', minHeight: 400 }}
    >
      {/* Background */}
      <rect width={layout.width} height={layout.height} fill="#fafafa" />

      {/* Stage indicator (top center) */}
      <g>
        <rect
          x={layout.width * 0.3}
          y={20}
          width={layout.width * 0.4}
          height={40}
          rx={4}
          fill="#2d3436"
        />
        <text
          x={layout.width / 2}
          y={46}
          textAnchor="middle"
          fill="white"
          fontSize={14}
          fontWeight="bold"
          letterSpacing={2}
        >
          STAGE
        </text>
      </g>

      {/* GA Zones — drawn behind seats */}
      {layout.ga_zones.map((zone) => (
        <GaZoneShape
          key={zone.id}
          zone={zone}
          onClick={() => onGaZoneClick(zone)}
        />
      ))}

      {/* Seats */}
      <g>
        {layout.seats.map((seat) => {
          const state = seatStates[seat.id] ?? 'available';
          const isSelected = selectedSeatIds.has(seat.id);
          const isHighlighted = highlightSeatId === seat.id;

          const fill = isSelected
            ? SEAT_COLORS.selected
            : SEAT_COLORS[state] ?? SEAT_COLORS.default;

          const section = sectionsByCode.get(seat.section_code);
          const isWheelchair = seat.attributes?.wheelchair === true;

          return (
            <g
              key={seat.id}
              transform={`translate(${seat.x} ${seat.y}) rotate(${seat.rotation})`}
              onClick={(e) => {
                e.stopPropagation();
                onSeatClick(seat);
              }}
              onMouseEnter={(e) => onSeatHover(seat, e)}
              onMouseLeave={() => onSeatHover(null)}
              style={{ cursor: state === 'available' ? 'pointer' : 'not-allowed' }}
            >
              {/* Highlight ring on hover */}
              {isHighlighted && (
                <circle
                  r={SEAT_RADIUS + 3}
                  fill="none"
                  stroke="#0984e3"
                  strokeWidth={2}
                />
              )}

              <circle
                r={SEAT_RADIUS}
                fill={fill}
                stroke={isSelected ? '#00b894' : '#ffffff'}
                strokeWidth={isSelected ? 2 : 1}
              />

              {/* Wheelchair symbol */}
              {isWheelchair && (
                <text
                  x={0}
                  y={3}
                  textAnchor="middle"
                  fill="white"
                  fontSize={8}
                  fontWeight="bold"
                  pointerEvents="none"
                >
                  ♿
                </text>
              )}
            </g>
          );
        })}
      </g>

      {/* Section labels (bottom of each section) */}
      {layout.sections.map((section) => {
        const sectionSeats = layout.seats.filter(
          (s) => s.section_code === section.code,
        );
        if (sectionSeats.length === 0) return null;

        const minX = Math.min(...sectionSeats.map((s) => s.x));
        const maxX = Math.max(...sectionSeats.map((s) => s.x));
        const maxY = Math.max(...sectionSeats.map((s) => s.y));

        return (
          <text
            key={section.id}
            x={(minX + maxX) / 2}
            y={maxY + 40}
            textAnchor="middle"
            fill="#636e72"
            fontSize={12}
            fontWeight="bold"
            letterSpacing={1}
          >
            {section.name.toUpperCase()}
          </text>
        );
      })}
    </svg>
  );
});

/**
 * GA zone — drawn as a semi-transparent rectangle with a label.
 * SVG coordinates come from the zone's `visual` object.
 */
function GaZoneShape({
  zone,
  onClick,
}: {
  zone: GaZoneEntry;
  onClick: () => void;
}) {
  const v = zone.visual as {
    path?: string;
    label_x?: number;
    label_y?: number;
    x?: number;
    y?: number;
    w?: number;
    h?: number;
  };

  // Prefer a path, fall back to a rectangle.
  const hasPath = typeof v.path === 'string';
  const hasRect =
    typeof v.x === 'number' &&
    typeof v.y === 'number' &&
    typeof v.w === 'number' &&
    typeof v.h === 'number';

  if (!hasPath && !hasRect) return null;

  return (
    <g
      onClick={(e) => {
        e.stopPropagation();
        onClick();
      }}
      style={{ cursor: 'pointer' }}
    >
      {hasPath ? (
        <path d={v.path!} fill="#fdcb6e" fillOpacity={0.25} stroke="#fdcb6e" strokeWidth={2} />
      ) : (
        <rect
          x={v.x}
          y={v.y}
          width={v.w}
          height={v.h}
          fill="#fdcb6e"
          fillOpacity={0.25}
          stroke="#fdcb6e"
          strokeWidth={2}
          rx={4}
        />
      )}
      {typeof v.label_x === 'number' && typeof v.label_y === 'number' && (
        <text
          x={v.label_x}
          y={v.label_y}
          textAnchor="middle"
          fill="#6c5ce7"
          fontSize={14}
          fontWeight="bold"
          pointerEvents="none"
        >
          {zone.name} · {zone.capacity}
        </text>
      )}
    </g>
  );
}