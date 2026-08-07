#!/usr/bin/env python3
import argparse
import csv
import json
from pathlib import Path


INPUT_FIELDS = ["EMAIL", "VORNAME", "NACHNAME", "ANREDE", "JOURFIXE"]
OUTPUT_FIELDS = ["email", "name", "attributes"]
TRUE_VALUES = {"1", "true", "yes", "y", "ja", "j", "x"}
DEFAULT_OUTPUT_DIR = Path(__file__).resolve().parent / "contacts"


def clean(value):
    return (value or "").strip()


def is_true(value):
    return clean(value).lower() in TRUE_VALUES


def output_row(row):
    first_name = clean(row.get("VORNAME"))
    last_name = clean(row.get("NACHNAME"))
    attributes = {
        "vorname": first_name,
        "nachname": last_name,
        "anrede": clean(row.get("ANREDE")),
        "jourfixe": is_true(row.get("JOURFIXE")),
    }

    return {
        "email": clean(row.get("EMAIL")),
        "name": " ".join(part for part in [first_name, last_name] if part),
        "attributes": json.dumps(attributes, ensure_ascii=False, separators=(",", ":")),
    }


def main():
    parser = argparse.ArgumentParser(
        description="Split a Brevo-style CSV into listmonk import CSVs."
    )
    parser.add_argument("input", help="Input CSV path. Expected delimiter: semicolon (;).")
    parser.add_argument(
        "--jourfixe-output",
        default=DEFAULT_OUTPUT_DIR / "contacts_jourfixe.csv",
        help="Output CSV for contacts where JOURFIXE is true.",
    )
    parser.add_argument(
        "--other-output",
        default=DEFAULT_OUTPUT_DIR / "contacts_other.csv",
        help="Output CSV for all remaining contacts.",
    )
    args = parser.parse_args()
    jourfixe_output = Path(args.jourfixe_output)
    other_output = Path(args.other_output)
    jourfixe_output.parent.mkdir(parents=True, exist_ok=True)
    other_output.parent.mkdir(parents=True, exist_ok=True)

    with open(args.input, newline="", encoding="utf-8-sig") as input_file:
        reader = csv.DictReader(input_file, delimiter=";")
        missing_fields = [field for field in INPUT_FIELDS if field not in (reader.fieldnames or [])]
        if missing_fields:
            raise SystemExit(f"Missing expected CSV fields: {', '.join(missing_fields)}")

        with open(jourfixe_output, "w", newline="", encoding="utf-8") as jourfixe_file:
            with open(other_output, "w", newline="", encoding="utf-8") as other_file:
                jourfixe_writer = csv.DictWriter(jourfixe_file, fieldnames=OUTPUT_FIELDS)
                other_writer = csv.DictWriter(other_file, fieldnames=OUTPUT_FIELDS)
                jourfixe_writer.writeheader()
                other_writer.writeheader()

                for row in reader:
                    writer = jourfixe_writer if is_true(row.get("JOURFIXE")) else other_writer
                    writer.writerow(output_row(row))


if __name__ == "__main__":
    main()
