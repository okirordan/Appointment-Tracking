#!/usr/bin/env python3
"""Extract Mail Manager's outgoing-mail MHTML table into deterministic JSON."""

from __future__ import annotations

import argparse
import gzip
import json
import re
import sys
from datetime import datetime
from email import policy
from email.parser import BytesParser
from html.parser import HTMLParser
from pathlib import Path


EXPECTED_HEADERS = ["From", "Received", "Subject", "Sent to", "Date sent"]
SPACE_PATTERN = re.compile(r"\s+")
RECEIVED_YEAR_CORRECTIONS = {
    "0202": 2025,
    "0204": 2024,
    "0206": 2026,
    "2926": 2026,
}
SENT_YEAR_CORRECTIONS = {
    "0205": 2025,
    "2014": 2024,
    "2027": 2026,
}


def normalize_cell(value: str | None) -> str:
    return SPACE_PATTERN.sub(" ", value or "").strip()


class TableParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.tables: list[list[list[str]]] = []
        self._table_depth = 0
        self._rows: list[list[str]] | None = None
        self._row: list[str] | None = None
        self._cell: list[str] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag == "table":
            self._table_depth += 1
            if self._table_depth == 1:
                self._rows = []
        elif self._table_depth == 1 and tag == "tr":
            self._row = []
        elif self._table_depth == 1 and tag in {"th", "td"}:
            self._cell = []

    def handle_data(self, data: str) -> None:
        if self._cell is not None:
            self._cell.append(data)

    def handle_endtag(self, tag: str) -> None:
        if self._table_depth == 1 and tag in {"th", "td"} and self._cell is not None:
            if self._row is not None:
                self._row.append(normalize_cell("".join(self._cell)))
            self._cell = None
        elif self._table_depth == 1 and tag == "tr" and self._row is not None:
            if self._rows is not None and self._row:
                self._rows.append(self._row)
            self._row = None
        elif tag == "table" and self._table_depth > 0:
            if self._table_depth == 1 and self._rows is not None:
                self.tables.append(self._rows)
                self._rows = None
            self._table_depth -= 1


def parse_date(
    raw_value: str, year_corrections: dict[str, int]
) -> tuple[str | None, bool]:
    if not raw_value:
        return None, False

    for pattern in ("%m/%d/%Y %I:%M:%S %p", "%m/%d/%Y"):
        try:
            parsed = datetime.strptime(raw_value, pattern)
            printed_year = raw_value.split("/", maxsplit=2)[-1].split(maxsplit=1)[0]
            corrected_year = year_corrections.get(printed_year)
            if corrected_year is not None:
                parsed = parsed.replace(year=corrected_year)

            return parsed.date().isoformat(), corrected_year is not None
        except ValueError:
            continue

    return None, False


def extract_table(mhtml_path: Path, expected_headers: list[str]) -> list[list[str]]:
    with mhtml_path.open("rb") as stream:
        message = BytesParser(policy=policy.default).parse(stream)

    html_part = message.get_body(preferencelist=("html",))
    if html_part is None:
        raise ValueError("MHTML does not contain an HTML body")

    parser = TableParser()
    parser.feed(html_part.get_content())
    matching_tables = [
        table for table in parser.tables if table and table[0] == expected_headers
    ]
    if len(matching_tables) != 1:
        raise ValueError(
            f"expected exactly one matching mail table, found {len(matching_tables)}"
        )

    return matching_tables[0][1:]


def extract(mhtml_path: Path) -> tuple[list[dict[str, object]], list[dict[str, object]]]:
    records: list[dict[str, object]] = []
    warnings: list[dict[str, object]] = []
    for sequence, cells in enumerate(
        extract_table(mhtml_path, EXPECTED_HEADERS), start=1
    ):
        if len(cells) != 5:
            raise ValueError(f"row {sequence}: expected 5 cells, found {len(cells)}")

        sender, received_raw, subject, recipient, sent_raw = cells
        received_date, received_date_corrected = parse_date(
            received_raw, RECEIVED_YEAR_CORRECTIONS
        )
        sent_date, sent_date_corrected = parse_date(sent_raw, SENT_YEAR_CORRECTIONS)
        record = {
            "sequence": sequence,
            "sender_name": sender,
            "recipient_name": recipient,
            "subject": subject,
            "received_date": received_date,
            "received_date_raw": received_raw,
            "received_date_corrected": received_date_corrected,
            "sent_date": sent_date,
            "sent_date_raw": sent_raw,
            "sent_date_corrected": sent_date_corrected,
        }
        records.append(record)

        missing = [
            name
            for name, value in (
                ("sender_name", sender),
                ("recipient_name", recipient),
                ("subject", subject),
                ("received_date", received_raw),
                ("sent_date", sent_raw),
            )
            if not value
        ]
        invalid_dates = [
            name
            for name, raw, parsed in (
                ("received_date", received_raw, received_date),
                ("sent_date", sent_raw, sent_date),
            )
            if raw and parsed is None
        ]
        if missing or invalid_dates:
            warnings.append(
                {
                    "sequence": sequence,
                    "missing": missing,
                    "invalid_dates": invalid_dates,
                    "received_date_raw": received_raw,
                    "sent_date_raw": sent_raw,
                }
            )

    return records, warnings


def write_json(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n"
    if path.suffix == ".gz":
        with gzip.open(path, "wt", encoding="utf-8", compresslevel=9) as stream:
            stream.write(payload)
    else:
        path.write_text(payload, encoding="utf-8")


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
