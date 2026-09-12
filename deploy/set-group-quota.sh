#!/bin/bash

set -e

GROUP="$1"
QUOTA="$2"

if [ -z "$GROUP" ] || [ -z "$QUOTA" ]; then
    echo "Usage: $0 <group> <quota>"
    echo "Example: $0 SOC '0 B'"
    exit 1
fi

echo "Getting members of group: $GROUP"

mapfile -t USERS < <(
    docker compose exec -T app php occ group:list "$GROUP" --output=json \
    | python3 -c "
import json
import sys

data = json.load(sys.stdin)
for user in data.get('$GROUP', []):
    print(user)
"
)

if [ "${#USERS[@]}" -eq 0 ]; then
    echo "No users found in group '$GROUP'."
    exit 0
fi

for USERNAME in "${USERS[@]}"; do
    echo "Setting quota '$QUOTA' for user: $USERNAME"

    docker compose exec -T app php occ user:setting \
        "$USERNAME" files quota "$QUOTA" < /dev/null
done

echo "Done. Processed ${#USERS[@]} user(s)."