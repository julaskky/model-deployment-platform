
import requests
import getpass

API_URL = (
    "http://localhost/model-deployment-platform/"
    "api/v1/models/iris-classifier-integration-test/predict"
)


api_key = input("Paste your complete API key: ").strip()

print("API key prefix:", api_key[:8] if api_key else "EMPTY")
print("API key length:", len(api_key))

headers = {
    "Authorization": f"Bearer {api_key}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

payload = {
    "features": [5.1, 3.5, 1.4, 0.2]
}

response = requests.post(
    API_URL,
    headers=headers,
    json=payload,
    timeout=40
)

print("\nHTTP Status:", response.status_code)
print("Content-Type:", response.headers.get("Content-Type"))
print("Response:")

try:
    print(response.json())
except ValueError:
    print(response.text)
