import { Controller, Get } from '@nestjs/common';

@Controller(['health', 'api/v1/health'])
export class HealthController {
  @Get()
  get(): { status: string; timestamp: string; version: string } {
    return {
      status: 'ok',
      version: '0.1.0',
      timestamp: new Date().toISOString(),
    };
  }
}