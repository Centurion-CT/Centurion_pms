// Dataclasses mirroring OutletResource + ProductResource +
// PosSaleResource. Manual serialisation so the schema is grep-able.

class Outlet {
  const Outlet({
    required this.id,
    required this.name,
    required this.slug,
    required this.type,
    required this.active,
    required this.taxRate,
  });

  final int id;
  final String name;
  final String slug;
  final String? type;
  final bool active;
  final String taxRate;

  factory Outlet.fromJson(Map<String, dynamic> j) => Outlet(
        id: j['id'] as int,
        name: j['name'] as String,
        slug: j['slug'] as String,
        type: j['type'] as String?,
        active: (j['active'] as bool?) ?? false,
        taxRate: (j['tax_rate'] as String?) ?? '0',
      );
}

class Product {
  const Product({
    required this.id,
    required this.name,
    required this.price,
    required this.active,
    required this.trackStock,
    required this.quantityOnHand,
    required this.lowStock,
    this.sku,
    this.barcode,
    this.description,
  });

  final int id;
  final String? sku;
  final String? barcode;
  final String name;
  final String? description;
  final int price;
  final bool active;
  final bool trackStock;
  final int quantityOnHand;
  final bool lowStock;

  factory Product.fromJson(Map<String, dynamic> j) => Product(
        id: j['id'] as int,
        sku: j['sku'] as String?,
        barcode: j['barcode'] as String?,
        name: j['name'] as String,
        description: j['description'] as String?,
        price: (j['price'] as int?) ?? 0,
        active: (j['active'] as bool?) ?? false,
        trackStock: (j['track_stock'] as bool?) ?? false,
        quantityOnHand: (j['quantity_on_hand'] as int?) ?? 0,
        lowStock: (j['low_stock'] as bool?) ?? false,
      );
}

// Client-side cart line — mutable so the operator can bump quantities
// in the cart drawer without a full state notifier.
class CartLine {
  CartLine({
    required this.product,
    this.quantity = 1,
  });

  final Product product;
  int quantity;

  int get lineTotal => product.price * quantity;
}

// Payment tender chosen at checkout.
class Tender {
  const Tender({required this.method, required this.amount, this.referenceNote});

  final String method;
  final int amount;
  final String? referenceNote;

  Map<String, dynamic> toJson() => {
        'method': method,
        'amount': amount,
        if (referenceNote != null) 'reference_note': referenceNote,
      };
}

class PosSaleReceipt {
  const PosSaleReceipt({
    required this.id,
    required this.reference,
    required this.status,
    required this.subtotal,
    required this.total,
    required this.lines,
    required this.payments,
    this.soldAt,
  });

  final int id;
  final String reference;
  final String? status;
  final int subtotal;
  final int total;
  final DateTime? soldAt;
  final List<PosReceiptLine> lines;
  final List<PosReceiptPayment> payments;

  factory PosSaleReceipt.fromJson(Map<String, dynamic> j) => PosSaleReceipt(
        id: j['id'] as int,
        reference: j['reference'] as String,
        status: j['status'] as String?,
        subtotal: (j['subtotal'] as int?) ?? 0,
        total: (j['total'] as int?) ?? 0,
        soldAt: j['sold_at'] != null ? DateTime.tryParse(j['sold_at'] as String) : null,
        lines: ((j['lines'] as List?) ?? const [])
            .map((e) => PosReceiptLine.fromJson((e as Map).cast<String, dynamic>()))
            .toList(),
        payments: ((j['payments'] as List?) ?? const [])
            .map((e) => PosReceiptPayment.fromJson((e as Map).cast<String, dynamic>()))
            .toList(),
      );
}

class PosReceiptLine {
  const PosReceiptLine({
    required this.id,
    required this.name,
    required this.quantity,
    required this.unitPrice,
    required this.lineTotal,
  });

  final int id;
  final String name;
  final int quantity;
  final int unitPrice;
  final int lineTotal;

  factory PosReceiptLine.fromJson(Map<String, dynamic> j) => PosReceiptLine(
        id: j['id'] as int,
        name: (j['name'] as String?) ?? '',
        quantity: (j['quantity'] as int?) ?? 0,
        unitPrice: (j['unit_price'] as int?) ?? 0,
        lineTotal: (j['line_total'] as int?) ?? 0,
      );
}

class PosReceiptPayment {
  const PosReceiptPayment({
    required this.id,
    required this.method,
    required this.amount,
    this.referenceNote,
  });

  final int id;
  final String method;
  final int amount;
  final String? referenceNote;

  factory PosReceiptPayment.fromJson(Map<String, dynamic> j) => PosReceiptPayment(
        id: j['id'] as int,
        method: (j['method'] as String?) ?? 'other',
        amount: (j['amount'] as int?) ?? 0,
        referenceNote: j['reference_note'] as String?,
      );
}
