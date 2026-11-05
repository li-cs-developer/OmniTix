use criterion::{black_box, criterion_group, criterion_main, Criterion};
use std::path::PathBuf;

use omnitix_crypto::crypto::keyring::Keyring;
use omnitix_crypto::crypto::signer::{sign_ticket, verify_ticket, TicketPayload};

fn test_keyring() -> Keyring {
    Keyring::load(&PathBuf::from("keys"), "v1").expect("test keyring")
}

fn sample_payload() -> TicketPayload {
    TicketPayload {
        t: "01HTICKET0000000000000001".to_string(),
        n: "OTX-ABC123DEF456".to_string(),
        e: "01HEVENT0000000000000001".to_string(),
        o: "01HOCC0000000000000000001".to_string(),
        s: Some("01HSEAT00000000000000001".to_string()),
        g: None,
        p: "01HTIER00000000000000001".to_string(),
        u: "01HUSER00000000000000001".to_string(),
        i: "2026-10-02T12:00:00Z".to_string(),
        x: "0".repeat(32),
    }
}

fn bench_sign(c: &mut Criterion) {
    let keyring = test_keyring();
    let payload = sample_payload();
    c.bench_function("sign_ticket", |b| {
        b.iter(|| {
            let signed = sign_ticket(black_box(&keyring), black_box(&payload)).unwrap();
            black_box(signed);
        })
    });
}

fn bench_verify(c: &mut Criterion) {
    let keyring = test_keyring();
    let payload = sample_payload();
    let signed = sign_ticket(&keyring, &payload).unwrap();
    c.bench_function("verify_ticket", |b| {
        b.iter(|| {
            let recovered = verify_ticket(black_box(&keyring), black_box(&signed.qr_string)).unwrap();
            black_box(recovered);
        })
    });
}

criterion_group!(benches, bench_sign, bench_verify);
criterion_main!(benches);