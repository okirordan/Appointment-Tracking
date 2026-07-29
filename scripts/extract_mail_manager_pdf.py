#!/usr/bin/env python3
"""Extract Mail Manager's printed incoming-mail table into deterministic JSON."""

from __future__ import annotations

import argparse
import gzip
import json
import re
import sys
from datetime import datetime
from pathlib import Path

import pypdfium2 as pdfium


EXPECTED_HEADERS = ["From", "To", "Subject", "Received", "Ref."]
SPACE_PATTERN = re.compile(r"\s+")
DATE_CORRECTIONS = {
    "15 April 0202": "2025-04-15",
    "16 April 0202": "2025-04-16",
    "25 March 0204": "2024-03-25",
    "07 April 0206": "2026-04-07",
    "17 April 0206": "2026-04-17",
}


def normalize_cell(value: str | None) -> str:
    # PDFium exposes the printout's custom-font hyphen glyph as U+0002.
    return SPACE_PATTERN.sub(" ", (value or "").replace("\x02", "-")).strip()


def cluster(values: list[float], tolerance: float = 1.2) -> list[float]:
    groups: list[list[float]] = []
    for value in sorted(values):
        if not groups or value - groups[-1][-1] > tolerance:
            groups.append([value])
        else:
            groups[-1].append(value)

    return [sum(group) / len(group) for group in groups]


def table_boundaries(page: pdfium.PdfPage) -> tuple[list[float], list[float]]:
    width, height = page.get_size()
    path_bounds = [
        obj.get_bounds()
        for obj in page.get_objects()
        if obj.type == 2  # FPDF_PAGEOBJ_PATH
    ]
    x_boundaries = cluster(
        [
            (left + right) / 2
            for left, bottom, right, top in path_bounds
            if right - left < 2 and top - bottom > 15 and 20 < left < width - 20
        ]
    )
    y_boundaries = cluster(
        [
            (bottom + top) / 2
            for left, bottom, right, top in path_bounds
            if top - bottom < 2 and right - left > 80 and 20 < left < width - 20
        ]
    )

    # The printout has exactly five columns. Browser borders appear as pairs of
    # edges less than one point apart, which cluster() intentionally collapses.
    if len(x_boundaries) != 6:
        raise ValueError(
            f"expected 6 column boundaries, found {x_boundaries}"
        )

    return x_boundaries, y_boundaries


def extract_page_rows(page: pdfium.PdfPage, page_number: int) -> list[list[str]]:
    x_boundaries, candidate_y_boundaries = table_boundaries(page)
    _, page_height = page.get_size()
    y_boundaries = sorted(
        (value for value in candidate_y_boundaries if 40 < value < page_height - 20),
        reverse=True,
    )
    text_page = page.get_textpage()
    rows: list[list[str]] = []
    for row_index in range(len(y_boundaries) - 1):
        top, bottom = y_boundaries[row_index], y_boundaries[row_index + 1]
        cells: list[str] = []
        for column_index in range(5):
            left, right = x_boundaries[column_index], x_boundaries[column_index + 1]
            cells.append(
                normalize_cell(
                    text_page.get_text_bounded(left + 1, bottom + 1, right - 1, top - 1)
                )
            )
        rows.append(cells)
    text_page.close()

    try:
        header_index = rows.index(EXPECTED_HEADERS)
    except ValueError as exception:
        raise ValueError(
            f"page {page_number}: expected table header not found in {rows[:3]}"
        ) from exception

    return rows[header_index + 1 :]


def parse_received_date(raw_value: str) -> str | None:
    if not raw_value:
        return None

    if raw_value in DATE_CORRECTIONS:
        return DATE_CORRECTIONS[raw_value]

    try:
        parsed = datetime.strptime(raw_value, "%d %B %Y")
    except ValueError:
        return None

    # MySQL DATE supports years beginning at 1000. Retain earlier or malformed
    # printed values separately rather than silently correcting source data.
    if parsed.year < 1000:
        return None

    return parsed.date().isoformat()


def extract(pdf_path: Path) -> tuple[list[dict[str, object]], list[dict[str, object]]]:
    records: list[dict[str, object]] = []
    warnings: list[dict[str, object]] = []

    pdf = pdfium.PdfDocument(pdf_path)
    try:
        total_pages = len(pdf)
        for page_index in range(1, total_pages + 1):
            page = pdf[page_index - 1]
            page_rows = extract_page_rows(page, page_index)
            page.close()
            for source_row, cells in enumerate(page_rows, start=1):
                sender, recipient, subject, received_raw, reference = cells
                sequence = len(records) + 1
                parsed_date = parse_received_date(received_raw)
                record = {
                    "sequence": sequence,
                    "source_page": page_index,
                    "source_row": source_row,
                    "sender_name": sender,
                    "recipient_name": recipient,
                    "subject": subject,
                    "received_date": parsed_date,
                    "received_date_raw": received_raw,
                    "correspondence_reference": reference or None,
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
                if missing or parsed_date is None:
                    warnings.append(
                        {
                            "sequence": sequence,
                            "source_page": page_index,
                            "source_row": source_row,
                            "missing": missing,
                            "received_date_raw": received_raw,
                        }
                    )

            if page_index == 1 or page_index % 100 == 0 or page_index == total_pages:
                print(
                    f"processed {page_index}/{total_pages} pages ({len(records)} records)",
                    file=sys.stderr,
                    flush=True,
                )
    finally:
        pdf.close()

    return records, warnings


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("pdf", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--warnings", type=Path)
    args = parser.parse_args()

    records, warnings = extract(args.pdf)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(records, ensure_ascii=False, separators=(",", ":")) + "\n"
    if args.output.suffix == ".gz":
        with gzip.open(args.output, "wt", encoding="utf-8", compresslevel=9) as stream:
            stream.write(payload)
    else:
        args.output.write_text(payload, encoding="utf-8")
    if args.warnings:
        args.warnings.parent.mkdir(parents=True, exist_ok=True)
        args.warnings.write_text(
            json.dumps(warnings, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )

    print(json.dumps({"records": len(records), "warnings": len(warnings)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
