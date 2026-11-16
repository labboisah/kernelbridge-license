import { createPublicKey, createVerify, verify as cryptoVerify } from 'node:crypto';
import type { EntitlementSignatureVerifier } from './types.js';

export class RsaSha256EntitlementVerifier implements EntitlementSignatureVerifier {
  constructor(private readonly publicKeys: string | Record<string, string>) {}

  verify(payload: string, signature: string, keyId?: string): boolean {
    const publicKey = typeof this.publicKeys === 'string' ? this.publicKeys : keyId ? this.publicKeys[keyId] : undefined;
    if (!publicKey) return false;
    const verifier = createVerify('RSA-SHA256');
    verifier.update(payload);
    verifier.end();
    return verifier.verify(publicKey, signature, 'base64');
  }
}

export class Ed25519EntitlementVerifier implements EntitlementSignatureVerifier {
  constructor(private readonly publicKeys: Readonly<Record<string, string>>) {}

  verify(payload: string, signature: string, keyId?: string): boolean {
    if (!keyId || !Object.hasOwn(this.publicKeys, keyId)) return false;

    try {
      const configuredKey = this.publicKeys[keyId]!;
      let key;
      try {
        key = createPublicKey(configuredKey);
      } catch {
        const rawKey = Buffer.from(configuredKey, 'base64');
        if (rawKey.length !== 32) return false;
        const spkiPrefix = Buffer.from('302a300506032b6570032100', 'hex');
        key = createPublicKey({
          key: Buffer.concat([spkiPrefix, rawKey]),
          format: 'der',
          type: 'spki',
        });
      }
      if (key.asymmetricKeyType !== 'ed25519') return false;
      return cryptoVerify(null, Buffer.from(payload, 'utf8'), key, Buffer.from(signature, 'base64'));
    } catch {
      return false;
    }
  }
}
