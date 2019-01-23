# VECRO

[VECRO](https://github.com/deemru/vecro) stands for a **v**erifiable **e**lliptic **c**urve **r**andom **o**racle.

VECRO allows to produce [unique, collision resistant and fully pseudorandom](https://tools.ietf.org/html/draft-irtf-cfrg-vrf-03#page-10) numbers based on client's data. These numbers can be easily verified as regular EdDSA signatures.

## Basics

[EdDSA](https://en.wikipedia.org/wiki/EdDSA) signature consists of `R` and `S` values, where `R` represents a nonce and `S` represents a signature, the `R, S` pair proofs that a message is signed by a private key. This can be verified by a corresponding public key at any time.

EdDSA has a problem when used as a source for a [random oracle](https://en.wikipedia.org/wiki/Random_oracle), because it can generate an infinite number of valid signatures for one message, so an oracle on this method can easily manipulate a final result. `R` value must be unique every time and even if `R` is fixed and based on a message input, there is no garantees that the oracle does not manipulate the value of `R`, otherwise, his private key is compromised.

VECRO defines a mechanism in which `R` value fixates before a signature generation, so for one message and fixed `R` there is only one `S` value, which can then be used as verifiable random number, because there is no room for manipulations.

## Solution

VECRO provides his public key and `getR()`, `getRS()` functions for clients.

`getR()` function:
- gets `rseed` value from a client;
- calculates `R` value based on `rseed`;
- publishes `R` for the client.

`getRS()` function:
- gets a `message` and `rseed` from a client;
- calculates a signature as `R, S` pair based on the `message` and `rseed`;
- publishes `R, S` for the client.

When a client wants a new random number, he:
- chooses a VECRO he wants to work with;
- gets the VECRO's public key;
- generates unique `rseed`;
- calls `getR( rseed )`  on the VECRO;
- gets `R` value from the VECRO;
- generates a `message`;
- calls `getRS( message, rseed )` on the VECRO;
- gets `R, S` pair from the VECRO;
- verifies `R` matches `R` from `R, S`;
- stops if not;
- verifies `R, S` is a signature of the `message` by the VECRO's public key;
- stops if not;
- uses `S` as a verified random value.

And there are a few important things here.

For a VECRO:
- `R` must be unique;
- `R` must be used only once.

For a client:
- VECRO must be chosen prior a `message` generation;
- `rseed` must be chosen prior a `message` generation;
- `R` that corresponds `rseed` must appear prior a `message` generation.

This is done to ensure that when the message is ready, no one can manipulate `S` as the final result.

## Cryptographic library implementation details

VECRO needs a few additional cryptographic library functions:
- to produce `R` value based on `rseed` and the VECRO's private key;
- to produce `R, S` pair based on a `message`, the VECRO's private key and `rseed`;
- `R` values in both calls must be equal if `rseed` is equal;
- `R, S` must be a `message` signature which is verifiable by VECRO's public key.

Beware of direct `rseed` usage, `rseed` which goes to `R` generation must include all available static identificators, such as addresses, keys and other fixed parameters.

Reference implementation @ [deemru / curve25519-php](https://github.com/deemru/curve25519-php):
- interface: [curve25519.php #L379](https://github.com/deemru/curve25519-php/blob/98cbc0db765b760f878cb66230e2f14ef88210f0/curve25519.php#L379)
- internal `rseed` usage: [curve25519.php #301](https://github.com/deemru/curve25519-php/blob/98cbc0db765b760f878cb66230e2f14ef88210f0/curve25519.php#L301)



## Blockchain implementation details

VECRO is designed to function on blockchains which have smart contracts which allow:
- to publish VECRO's public key once and for all;
- to publish `R` value identified by client's `rseed`, public key and transaction id;
- to overwrite `R` value by `R, S` pair only if there is a transaction with the same client's public key, with the same `rseed`, with a `message` for which `R, S` is a signature verified by VECRO's public key.
