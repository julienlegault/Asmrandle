import json
import re
import requests
import time

INPUT_FILE = "oracle-cards.json"
OUTPUT_FILE = "formatted_card_list.json"
EDHREC_BASE_URL = "https://json.edhrec.com/pages/cards/"

# Delay between API checks to avoid hammering EDHREC
REQUEST_DELAY = 0.2  # seconds

def normalize_name(name: str) -> str:
    """Normalize card names to lowercase, remove punctuation, replace spaces with hyphens."""
    name = name.lower()
    name = re.sub(r"[^a-z0-9\- ]+", "", name)  # remove non-alphanumeric except spaces
    name = re.sub(r"\s+", "-", name.strip())  # replace spaces with hyphens
    return name

def is_valid_edhrec_card(name: str) -> bool:
    """Check if a given normalized card name has a valid EDHREC JSON page."""
    url = f"{EDHREC_BASE_URL}{name}.json"
    try:
        response = requests.get(url, timeout=5)
        if response.status_code != 200:
            return False
        data = response.json()
        # minimal validation: ensure expected keys exist
        card_info = data.get("container", {}).get("json_dict", {}).get("card", {})
        return "inclusion" in card_info and "potential_decks" in card_info
    except Exception:
        return False

def main():
    with open(INPUT_FILE, "r", encoding="utf-8") as f:
        cards = json.load(f)

    formatted = []
    seen = set()

    print(f"Checking {len(cards)} cards against EDHREC... (this may take a while)")

    for i, card in enumerate(cards, 1):
        norm = normalize_name(card["name"])
        if norm in seen:
            continue

        if is_valid_edhrec_card(norm):
            formatted.append(norm)
            seen.add(norm)
           # print(f"[{i}] ✅ {norm}")
        else:
           print(f"[{i}] ❌ {norm}")

        time.sleep(REQUEST_DELAY)

    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(formatted, f, indent=2)

    print(f"\n✅ Finished! {len(formatted)} valid cards saved to {OUTPUT_FILE}")

if __name__ == "__main__":
    main()
