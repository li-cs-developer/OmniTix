package grpc

import (
	"fmt"
	"net"
	"time"

	inventoryv1 "github.com/omnitix/go-inventory/gen/go/omnitix/inventory/v1"
	"github.com/omnitix/go-inventory/internal/logger"
	"go.uber.org/zap"
	"google.golang.org/grpc"
	"google.golang.org/grpc/reflection"
)

// Server wraps the gRPC server lifecycle.
type Server struct {
	grpc *grpc.Server
	lis  net.Listener
	log  *logger.Logger
}

// NewServer creates and starts a gRPC server on the given port.
func NewServer(port int, handler *InventoryHandler, log *logger.Logger) (*Server, error) {
	lis, err := net.Listen("tcp", fmt.Sprintf(":%d", port))
	if err != nil {
		return nil, fmt.Errorf("listen on :%d: %w", port, err)
	}

	grpcServer := grpc.NewServer(
		grpc.MaxRecvMsgSize(4*1024*1024),  // 4MB
		grpc.MaxSendMsgSize(4*1024*1024),  // 4MB
	)

	// Register our service
	inventoryv1.RegisterInventoryServiceServer(grpcServer, handler)

	// Enable server reflection — lets grpcurl discover methods without .proto files
	reflection.Register(grpcServer)

	srv := &Server{
		grpc: grpcServer,
		lis:  lis,
		log:  log.Named("grpc_server"),
	}

	// Start serving in a goroutine
	go func() {
		srv.log.Info("gRPC server listening",
			zap.Int("port", port),
			zap.String("service", "InventoryService"),
		)
		if err := grpcServer.Serve(lis); err != nil {
			srv.log.Error("gRPC server stopped", zap.Error(err))
		}
	}()

	return srv, nil
}

// Stop gracefully shuts down the gRPC server, waiting for in-flight RPCs
// up to the given timeout.
func (s *Server) Stop(timeout time.Duration) {
	done := make(chan struct{})
	go func() {
		s.grpc.GracefulStop()
		close(done)
	}()

	select {
	case <-done:
		s.log.Info("gRPC server stopped gracefully")
	case <-time.After(timeout):
		s.log.Warn("gRPC graceful stop timed out, forcing")
		s.grpc.Stop()
	}
}