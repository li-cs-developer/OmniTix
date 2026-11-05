import { io } from 'socket.io-client';

const socket = io('http://localhost:3001', {
  path: '/ws',
  transports: ['websocket', 'polling'],
  reconnection: false,
  timeout: 5000,
});

socket.on('connect', () => {
  console.log('✅ connected:', socket.id);
  socket.emit('message', {
    type: 'subscribe',
    event_occurrence_id: 'grpcurl-test-01',
  });
});

socket.on('connect_error', (err) => {
  console.error('❌ connect_error:', err.message);
});

socket.on('message', (msg) => {
  console.log('📨 received:', JSON.stringify(msg));
});

// 60 seconds is enough time to open Terminal B and produce a message.
setTimeout(() => {
  console.log('closing...');
  socket.close();
  process.exit(0);
}, 60000);