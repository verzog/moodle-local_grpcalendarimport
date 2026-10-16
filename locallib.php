<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Local library functions for the GRP Calendar Import plugin.
 *
 * These functions are kept for backwards compatibility; the logic lives in
 * \local_grpcalendarimport\importer, which other plugins should call instead.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Parse a CSV or TSV file into an array of associative arrays keyed by header.
 *
 * @param string $filepath Absolute path to the uploaded file.
 * @param array|null $headers Set to the normalised header names found in the file.
 * @return array Array of rows, each row is an associative array.
 */
function local_grpcalendarimport_parse_csv(string $filepath, ?array &$headers = null): array {
    return \local_grpcalendarimport\importer::parse_file($filepath, $headers);
}

/**
 * Rows of the sample CSV shown on the import page and offered for download.
 *
 * Start times are DD/MM/YYYY HH:MM, read in the uploading user's time zone.
 *
 * @return string[] CSV lines, header first, without line endings.
 */
function local_grpcalendarimport_sample_csv_lines(): array {
    return [
        'name,courseid,groupid,timestart,timeduration,description,location,eventtype,visible',
        'Team Meeting,2,5,03/05/2027 09:00,3600,Weekly sync,Room 101,group,1',
        'Practice Session,2,6,04/05/2027 16:00,5400,Pre-game warmup,Field B,group,1',
        'Championship Race,3,7,07/06/2027 10:00,7200,Regional finals,Track A,group,1',
    ];
}

/**
 * Columns that every import file must contain.
 *
 * @return string[]
 */
function local_grpcalendarimport_required_columns(): array {
    return \local_grpcalendarimport\importer::required_columns();
}

/**
 * Work out which required columns are missing from a file's header row.
 *
 * @param string[] $headers Normalised header names, as returned by local_grpcalendarimport_parse_csv().
 * @return string[] The missing column names, empty if none are missing.
 */
function local_grpcalendarimport_missing_columns(array $headers): array {
    return \local_grpcalendarimport\importer::missing_columns($headers);
}

/**
 * Read an integer column from a CSV row, treating a missing or blank cell as "use the default".
 *
 * @param array $row Associative array of CSV columns.
 * @param string $column Column name.
 * @param int|null $default Value to use when the cell is missing or blank.
 * @return int|null
 */
function local_grpcalendarimport_int_column(array $row, string $column, ?int $default): ?int {
    return \local_grpcalendarimport\importer::int_column($row, $column, $default);
}

/**
 * Create a single group calendar event from a CSV row.
 *
 * @param array $row Associative array of CSV columns.
 * @param bool $skipduplicates Whether to skip events that already exist.
 * @param int $rownum 1-based row number for reporting.
 * @return array Result array with keys: row, status (ok|skip|error), message.
 */
function local_grpcalendarimport_create_event(array $row, bool $skipduplicates, int $rownum): array {
    return \local_grpcalendarimport\importer::create_event($row, $skipduplicates, $rownum);
}
