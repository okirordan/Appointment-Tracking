#!/usr/bin/env python3
"""Extract Mail Manager's incoming-mail MHTML table into deterministic JSON."""

from __future__ import annotations

import argparse
import json
from datetime import datetime
from pathlib import Path

from extract_mail_manager_mhtml import extract_table, write_json


EXPECTED_HEADERS = ["From", "To", "Subject", "Received", "Ref."]
DATE_CORRECTIONS = {
    "15 April 0202": "2025-04-15",
    "16 April 0202": "2025-04-16",
    "25 March 0204": "2024-03-25",
    "07 April 0206": "2026-04-07",
    "17 April 0206": "2026-04-17",
}


def parse_received_date(raw_value: str) -> tuple[str | None, bool]:
    if not raw_value:
        return None, False

    if raw_value in DATE_CORRECTIONS:
        return DATE_CORRECTIONS[raw_value], True

    try:
        parsed = datetime.strptime(raw_value, "%d %B %Y")
    except ValueError:
        return None, False

    if parsed.year < 1000:
        return None, False

    return parsed.date().isoformat(), False


def extract(mhtml_path: Path) -> tuple[list[dict[str, object]], list[dict[str, object]]]:
    records: list[dict[str, object]] = []
    warnings: list[dict[str, object]] = []

    source_rows = extract_table(mhtml_path, EXPECTED_HEADERS)
    for sequence, cells in enumerate(source_rows, start=1):
        source_incomplete = len(cells) != 5
        if len(cells) != 5:
            warnings.append(
                {
                    "sequence": sequence,
                    "missing": ["received_date", "correspondence_reference"],
                    "invalid_dates": [],
                    "received_date_raw": "",
                    "partial_cells": cells,
                }
            )
            cells = [*cells, *([""] * (5 - len(cells)))]

        sender, recipient, subject, received_raw, reference = cells
        received_date, received_date_corrected = parse_received_date(received_raw)
        record = {
            "sequence": sequence,
            "sender_name": sender,
            "recipient_name": recipient,
            "subject": subject,
            "received_date": received_date,
            "received_date_raw": received_raw,
            "received_date_corrected": received_date_corrected,
            "correspondence_reference": reference or None,
            "source_incomplete": source_incomplete,
        }
        records.append(record)

        missing = [
            name
            for name, value in (
                ("sender_name", sender),
                ("recipient_name", recipient),
                ("subject", subject),
                ("received_date", received_raw),
            )
            if not value
        ]
        invalid_dates = ["received_date"] if received_raw and received_date is None else []
        if (missing or invalid_dates) and not source_incomplete:
            warnings.append(
                {
                    "sequence": sequence,
                    "missing": missing,
                    "invalid_dates": invalid_dates,
                    "received_date_raw": received_raw,
                }
            )

    return records, warnings


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("mhtml", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--warnings", type=Path)
    args = parser.parse_args()

    records, warnings = extract(args.mhtml)
    write_json(args.output, records)
    if args.warnings:
        write_json(args.warnings, warnings)

    print(json.dumps({"records": len(records), "warnings": len(warnings)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
