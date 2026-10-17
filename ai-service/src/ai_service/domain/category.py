"""Intención del email (categoría).

Mismos valores que `App\\AI\\Classification\\EmailIntent` de Laravel y que el
enum `category` del contrato de eventos (`/contracts/events/common.schema.json`);
un test de contrato comprueba que no se separan.
"""

from enum import StrEnum


class Category(StrEnum):
    ORDER_STATUS = "order_status"
    RETURN_REQUEST = "return_request"
    CANCELLATION = "cancellation"
    INVOICE_REQUEST = "invoice_request"
    BILLING_ISSUE = "billing_issue"
    PRODUCT_QUESTION = "product_question"
    TECHNICAL_SUPPORT = "technical_support"
    COMPLAINT = "complaint"
    SALES_INQUIRY = "sales_inquiry"
    ACCOUNT_CHANGE = "account_change"
    FEEDBACK = "feedback"
    AUTO_REPLY = "auto_reply"
    SPAM = "spam"
    OTHER = "other"
