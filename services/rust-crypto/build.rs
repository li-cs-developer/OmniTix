use std::path::PathBuf;

fn main() -> Result<(), Box<dyn std::error::Error>> {
    // Use the vendored protoc — ships with all well-known types bundled.
    // This makes the build reproducible across machines and distros.
    let protoc_path = protoc_bin_vendored::protoc_bin_path()?;
    std::env::set_var("PROTOC", protoc_path);

    let proto_root = PathBuf::from("../../contracts/proto");

    let proto_files = [
        proto_root.join("omnitix/common/v1/common.proto"),
        proto_root.join("omnitix/crypto/v1/crypto.proto"),
    ];

    for f in &proto_files {
        if !f.exists() {
            panic!("proto file not found: {}", f.display());
        }
    }

    tonic_build::configure()
        .build_server(true)
        .build_client(true)
        .out_dir("src/generated")
        .file_descriptor_set_path("src/generated/descriptor.bin")
        .compile_protos(&proto_files, &[proto_root])?;

    println!("cargo:rerun-if-changed=../../contracts/proto");
    println!("cargo:rerun-if-changed=build.rs");

    Ok(())
}