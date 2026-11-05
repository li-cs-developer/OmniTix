use anyhow::{Context, Result};
use serde::Serialize;

/// Serialize a value using RFC 8785 JSON Canonicalization Scheme.
///
/// This produces a deterministic byte sequence: keys are sorted
/// lexicographically, no whitespace, minimal escaping.
///
/// Any two implementations of RFC 8785 must produce identical output for
/// the same input. This is what makes our Ed25519 signatures verifiable
/// by any client, in any language.
pub fn canonical_json<T: Serialize>(value: &T) -> Result<Vec<u8>> {
    serde_json_canonicalizer::to_vec(value)
        .context("failed to canonicalize JSON")
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn sorts_keys() {
        let a = canonical_json(&json!({"b": 2, "a": 1})).unwrap();
        let b = canonical_json(&json!({"a": 1, "b": 2})).unwrap();
        assert_eq!(a, b);
    }

    #[test]
    fn no_whitespace() {
        let out = canonical_json(&json!({"a": 1, "b": 2})).unwrap();
        assert_eq!(std::str::from_utf8(&out).unwrap(), r#"{"a":1,"b":2}"#);
    }

    #[test]
    fn nested_sorted() {
        let out = canonical_json(&json!({"z": {"y": 1, "x": 2}})).unwrap();
        assert_eq!(std::str::from_utf8(&out).unwrap(), r#"{"z":{"x":2,"y":1}}"#);
    }
}