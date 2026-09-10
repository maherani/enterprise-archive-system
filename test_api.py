import requests 
from requests.auth import HTTPBasicAuth

# API Configuration
NEXTCLOUD_URL = "http://localhost"
USERNAME = "api_worker"
APP_PASSWORD = "5NJ8SmJLllNypBwaus3TmQwhdbjDdYQ4PFwbUz6h4LJtiMbA14QwyvCazozux7lh8aOKc72b" # Replace with the generated app password

# WebDAV API Endpoint
upload_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/{USERNAME}/audit_report_2026.txt"
file_content = "This is a confidential audit report generated via external AI Agent."

print("Initiating API connection to Enterprise Archive...")

# Uploading document via API
response = requests.put(
    upload_url,
    data=file_content,
    auth=HTTPBasicAuth(USERNAME, APP_PASSWORD)
)

if response.status_code in (201, 204):
    print("Success! Document successfully integrated and archived via API.")
else:
    print(f"Failed. Status code: {response.status_code}")




