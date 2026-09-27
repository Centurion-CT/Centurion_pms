// Dataclasses mirroring StockRequestResource. Product reuses the
// POS ProductResource shape so we import it there.

class StockRequestSummary {
  const StockRequestSummary({
    required this.id,
    required this.outletName,
    required this.productName,
    required this.quantity,
    required this.status,
    required this.requestedAt,
    this.approvedQuantity,
    this.requestedNote,
    this.resolvedNote,
    this.resolvedAt,
  });

  final int id;
  final String? outletName;
  final String? productName;
  final int quantity;
  final int? approvedQuantity;
  final String? status;
  final String? requestedNote;
  final String? resolvedNote;
  final DateTime? requestedAt;
  final DateTime? resolvedAt;

  factory StockRequestSummary.fromJson(Map<String, dynamic> j) => StockRequestSummary(
        id: j['id'] as int,
        outletName: j['outlet_name'] as String?,
        productName: j['product_name'] as String?,
        quantity: (j['quantity'] as int?) ?? 0,
        approvedQuantity: j['approved_quantity'] as int?,
        status: j['status'] as String?,
        requestedNote: j['requested_note'] as String?,
        resolvedNote: j['resolved_note'] as String?,
        requestedAt: j['requested_at'] != null
            ? DateTime.tryParse(j['requested_at'] as String)
            : null,
        resolvedAt: j['resolved_at'] != null
            ? DateTime.tryParse(j['resolved_at'] as String)
            : null,
      );
}
