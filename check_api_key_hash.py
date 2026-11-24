import hashlib
import getpass

api_key = input("Paste your replacement API key: ").strip()

key_hash = hashlib.sha256(
    api_key.encode("utf-8")
).hexdigest()

print("\nKey prefix:", api_key[:16])
print("Key length:", len(api_key))
print("SHA-256:", key_hash)
