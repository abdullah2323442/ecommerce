"""Builds the Pricing chapter QA report (features 22-35) as a Word file from the screenshots in docs/qa/screenshots."""
from pathlib import Path

from docx import Document
from docx.shared import Cm, Pt, RGBColor

ROOT = Path(__file__).parent
SHOTS = ROOT / "screenshots"
OUT = ROOT / "Pricing-QA-report.docx"

# (number, feature, status, plain explanation, evidence)
ROWS = [
    (22, "Tier system", "Verified", "The shop can group paikari customers into tiers A, B and C, each with its own discount.", ["22-tier-backend.jpg"]),
    (23, "Company-level rate", "Not checked", "A discount set for a whole company. Not reached in this pass.", []),
    (24, "Product-level override", "Not checked", "A discount set for one product. Not reached in this pass.", []),
    (25, "Customer-specific manual rate", "Partly verified", "A price set by hand for one customer on one medicine. The section exists on the customer page; adding a rate was not tested.", ["25-manual-rate-backend.jpg"]),
    (26, "Four-layer priority", "Not checked", "Manual rate beats product, then company, then tier. Not tested here.", []),
    (27, "Manual rate unchanged when tier changes", "Partly verified", "Changing a tier leaves hand-set rates alone. The page states this; the rate was not tested through a tier change.", ["22-tier-backend.jpg"]),
    (28, "Percentage and taka", "Not checked", "A discount can be a percentage or a fixed amount.", []),
    (29, "Percentage/taka toggle", "Not checked", "Switch between percentage and taka. Not reached in this pass.", []),
    (30, "Quantity-based discount steps", "Not checked", "Bigger orders get bigger discounts. Not reached in this pass.", []),
    (31, "Discount steps shown on product page", "Not found on sample", "The product page should list quantity discount steps. The sampled product (Matte silicone phone case) shows a price and delivery charge only, so the steps may not be set on it.", ["31-product-frontend.jpg"]),
    (32, "Discount order", "Not checked", "The order in which price, quantity, token and credit discounts apply. Not reached in this pass.", []),
    (33, "Warning on abnormal prices", "Not checked", "A warning when a price looks wrong. Not reached in this pass.", []),
    (34, "Tier change log", "Verified", "Each tier change is written to the customer's history. Tested by moving a test customer from B to C; the history showed the change and the customer was moved back to B afterwards.", ["34-tier-changed-backend.jpg", "34-tier-log-backend.jpg"]),
    (35, "Invoice stores that day's price", "Not checked", "An invoice keeps the price that applied on the day it was issued. Not reached in this pass.", []),
]

doc = Document()
for s in doc.sections:
    s.left_margin = s.right_margin = Cm(2)
    s.top_margin = s.bottom_margin = Cm(2)

doc.add_heading("Pricing: QA report (features 22 to 35)", level=0)
p = doc.add_paragraph("Siam's Aqua. Checked on the local dev site (127.0.0.1:8000) as the dev admin account. "
                      "Each feature was tested by using the screen, not by reading the code. Screenshots are from the built-in browser pane.")
p.runs[0].font.size = Pt(10)

done = sum(1 for r in ROWS if r[2] == "Verified")
partial = sum(1 for r in ROWS if r[2] == "Partly verified")
doc.add_paragraph(f"Summary: {done} verified, {partial} partly verified, {len(ROWS) - done - partial} not yet checked or not found.")

for num, name, status, explain, shots in ROWS:
    doc.add_heading(f"{num}. {name}", level=2)
    para = doc.add_paragraph()
    run = para.add_run(f"Status: {status}")
    run.bold = True
    run.font.color.rgb = RGBColor(0x1F, 0x4E, 0x3D) if status == "Verified" else RGBColor(0x55, 0x55, 0x55)
    doc.add_paragraph(explain)
    for shot in shots:
        path = SHOTS / shot
        if path.exists():
            doc.add_picture(str(path), width=Cm(11))
            cap = doc.add_paragraph(f"Screenshot: {shot}")
            cap.runs[0].font.size = Pt(8)

doc.save(OUT)
print(f"saved {OUT}")
