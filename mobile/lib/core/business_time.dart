import 'package:intl/intl.dart';

/// Darak's business time: Saudi Arabia, `Asia/Riyadh` — UTC+03:00 all year
/// (the kingdom observes no daylight saving time).
///
/// Every time the technician reads — a visit's appointment, "last synced" — is
/// shown in Riyadh time, whatever zone the phone itself is set to.
///
/// The server sends ISO-8601 with an offset (`2026-10-07T07:30:00+03:00`).
/// `DateTime.parse` turns that into a UTC instant, and formatting it directly
/// printed the UTC fields: a 07:30 visit showed as 04:30 on every phone.
/// `toLocal()` would only be right on phones whose zone is right; a phone left
/// on a technician's home-country zone, or an emulator on UTC, would still
/// show the wrong time for an appointment agreed with a customer. The time on
/// the screen must be the time at the site, so the offset is applied
/// explicitly.
abstract final class BusinessTime {
  static const zoneName = 'Asia/Riyadh';
  static const utcOffset = Duration(hours: 3);

  /// [instant]'s Riyadh wall-clock fields, flagged UTC so nothing downstream
  /// converts them again. For display and day arithmetic only.
  static DateTime wallClock(DateTime instant) => instant.toUtc().add(utcOffset);

  /// An ISO-8601 timestamp. With an offset or `Z` (what the server sends) it
  /// is an exact instant; without one Dart reads it as device-local, which is
  /// how the phone stores its own timestamps (e.g. `last_synced_at`).
  static DateTime? parse(String? iso) =>
      iso == null || iso.isEmpty ? null : DateTime.tryParse(iso);

  /// `07:30`.
  static String time(DateTime instant, [String? locale]) =>
      DateFormat('HH:mm', locale).format(wallClock(instant));

  /// `2026-10-07 07:30`.
  static String dateTime(DateTime instant) =>
      DateFormat('yyyy-MM-dd HH:mm').format(wallClock(instant));

  /// Riyadh calendar days from [now] to [instant]: 0 today, 1 tomorrow, -1
  /// yesterday.
  static int dayOffset(DateTime instant, DateTime now) {
    final a = wallClock(instant);
    final b = wallClock(now);
    return DateTime.utc(
      a.year,
      a.month,
      a.day,
    ).difference(DateTime.utc(b.year, b.month, b.day)).inDays;
  }

  /// Null for today; otherwise a short label for a visit on another day —
  /// `غداً`, `أمس` (translated by the UI) or the weekday and date.
  static String? dayLabel(DateTime instant, DateTime now, [String? locale]) =>
      switch (dayOffset(instant, now)) {
        0 => null,
        1 => 'غداً',
        -1 => 'أمس',
        _ => DateFormat('EEE d/M', locale).format(wallClock(instant)),
      };
}
