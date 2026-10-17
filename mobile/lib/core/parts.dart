import 'dart:convert';

import 'event_queue.dart';
import 'local_db.dart';

/// A part in the technician's van, as the last bootstrap saw it. No prices:
/// the server keeps those off the phone (contract C5).
class VanPart {
  const VanPart({
    required this.partId,
    required this.name,
    required this.qty,
    this.sku,
    this.qrCode,
    this.unit,
  });

  factory VanPart.fromJson(Map<String, dynamic> json) => VanPart(
    partId: (json['part_id'] as num).toInt(),
    name: '${json['name'] ?? ''}',
    qty: (json['qty'] as num?)?.toDouble() ?? 0,
    sku: json['sku'] as String?,
    qrCode: json['qr_code'] as String?,
    unit: json['unit'] as String?,
  );

  final int partId;
  final String name;
  final double qty;
  final String? sku;
  final String? qrCode;
  final String? unit;

  /// A scanned label or a typed code.
  bool matchesCode(String code) {
    final wanted = code.trim().toUpperCase();
    return wanted.isNotEmpty &&
        (qrCode?.toUpperCase() == wanted || sku?.toUpperCase() == wanted);
  }
}

/// The van's stock location and what it held at the last refresh.
class VehicleStock {
  const VehicleStock({
    required this.locationId,
    required this.name,
    required this.parts,
  });

  static const _key = 'vehicle_stock';

  final int locationId;
  final String name;
  final List<VanPart> parts;

  static VehicleStock? fromJson(Object? json) {
    if (json is! Map) return null;
    final id = json['location_id'];
    if (id is! num) return null;
    return VehicleStock(
      locationId: id.toInt(),
      name: '${json['name'] ?? ''}',
      parts: (json['parts'] as List? ?? const [])
          .whereType<Map>()
          .map((row) => VanPart.fromJson(Map<String, dynamic>.from(row)))
          .toList(),
    );
  }

  /// Kept in the encrypted database's key-value table, refreshed by every
  /// bootstrap. A server too old to send it leaves the last copy alone.
  static Future<void> store(LocalDb db, Object? json) =>
      db.setValue(_key, jsonEncode(json));

  static Future<VehicleStock?> load(LocalDb db) async {
    final raw = await db.getValue(_key);
    if (raw == null) return null;
    try {
      return fromJson(jsonDecode(raw));
    } on FormatException {
      return null;
    }
  }
}

/// A part this phone issued to a visit, and where that issue stands.
class IssuedPart {
  const IssuedPart({
    required this.clientEventId,
    required this.partId,
    required this.qty,
    required this.status,
    this.lastError,
  });

  factory IssuedPart.fromEvent(QueuedEvent event) => IssuedPart(
    clientEventId: event.clientEventId,
    partId: (event.payload['part_id'] as num).toInt(),
    qty: (event.payload['qty'] as num).toDouble(),
    status: event.status,
    lastError: event.lastError,
  );

  final String clientEventId;
  final int partId;
  final double qty;
  final QueuedStatus status;
  final String? lastError;

  /// Still on the phone: it can be withdrawn, and the van has not been
  /// charged for it on the server yet.
  bool get unsent => status == QueuedStatus.pending;
  bool get refused => status == QueuedStatus.failed;
}

/// What is left in the van for this phone to issue: the last refresh minus
/// issues that have not reached the server yet (once they have, the next
/// refresh already counts them). A hint — the server has the final word.
double availableInVan(VanPart part, Iterable<IssuedPart> issuedEverywhere) {
  final unsent = issuedEverywhere
      .where((issue) => issue.partId == part.partId && issue.unsent)
      .fold<double>(0, (sum, issue) => sum + issue.qty);
  final left = part.qty - unsent;
  return left < 0 ? 0 : left;
}
