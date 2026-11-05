// Auto-populated by build.rs.
// Do not edit manually.

pub mod omnitix {
    pub mod common {
        pub mod v1 {
            include!("omnitix.common.v1.rs");
        }
    }
    pub mod crypto {
        pub mod v1 {
            include!("omnitix.crypto.v1.rs");
        }
    }
}

/// Include the compiled file descriptor set for gRPC reflection.
pub const FILE_DESCRIPTOR_SET: &[u8] = include_bytes!("descriptor.bin");