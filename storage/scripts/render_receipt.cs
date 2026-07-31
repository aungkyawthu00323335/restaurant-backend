using System;
using System.IO;
using System.Drawing;
using System.Drawing.Imaging;
using System.Drawing.Text;
using System.Collections.Generic;
using System.Text.RegularExpressions;
using System.Web.Script.Serialization;

public class PrintItem
{
    public string type { get; set; } // text, divider, blank, item_row, receipt_row, meta_row
    public string text { get; set; }
    public string left { get; set; }
    public string right { get; set; }
    
    // 4-column fields for cashier vouchers
    public string item_name { get; set; }
    public string qty { get; set; }
    public string price { get; set; }
    public string amount { get; set; }

    public int font_size { get; set; }
    public bool is_bold { get; set; }
    public bool is_center { get; set; }
    public bool is_indented { get; set; }
}

public class PrintData
{
    public string paper_size { get; set; }
    public List<PrintItem> lines { get; set; }
}

public class Program
{
    public static void Main(string[] args)
    {
        try
        {
            string jsonText = "";
            if (args.Length > 0 && File.Exists(args[0]))
            {
                jsonText = File.ReadAllText(args[0], System.Text.Encoding.UTF8);
            }
            else
            {
                using (StreamReader reader = new StreamReader(Console.OpenStandardInput(), System.Text.Encoding.UTF8))
                {
                    jsonText = reader.ReadToEnd();
                }
            }

            if (string.IsNullOrEmpty(jsonText)) return;

            JavaScriptSerializer serializer = new JavaScriptSerializer();
            PrintData data = serializer.Deserialize<PrintData>(jsonText);

            bool is58mm = data.paper_size == "58mm";
            int width = is58mm ? 384 : 576;
            int padding = 20;

            string fontFamilyLatin = "Calibri";
            string fontFamilyMyanmar = "Myanmar Text";

            int calculatedHeight = padding * 2;

            if (data.lines != null)
            {
                foreach (var item in data.lines)
                {
                    if (item.type == "divider") calculatedHeight += 18;
                    else if (item.type == "blank") calculatedHeight += 14;
                    else if (item.type == "item_row") calculatedHeight += 40;
                    else if (item.type == "receipt_row") calculatedHeight += 40;
                    else if (item.type == "meta_row") calculatedHeight += 36;
                    else if (item.type == "text")
                    {
                        if (item.text != null && item.text.StartsWith("[OUTLET_LOGO:"))
                        {
                            calculatedHeight += 80;
                            continue;
                        }
                        int fSize = item.font_size > 0 ? item.font_size : 17;
                        calculatedHeight += (fSize + 16);
                    }
                }
            }

            calculatedHeight += 36;

            using (Bitmap bmp = new Bitmap(width, calculatedHeight))
            using (Graphics g = Graphics.FromImage(bmp))
            {
                g.Clear(Color.White);
                g.TextRenderingHint = TextRenderingHint.SingleBitPerPixelGridFit;

                using (Brush brush = new SolidBrush(Color.Black))
                using (Pen pen = new Pen(Color.Black, 2))
                using (Pen penDashed = new Pen(Color.Black, 1) { DashStyle = System.Drawing.Drawing2D.DashStyle.Dash })
                {
                    int currentY = padding;

                    if (data.lines != null)
                    {
                        foreach (var item in data.lines)
                        {
                            if (item.type == "blank")
                            {
                                currentY += 14;
                                continue;
                            }

                            if (item.type == "divider")
                            {
                                currentY += 4;
                                if (item.text == "-" || item.left == "-")
                                {
                                    g.DrawLine(penDashed, padding, currentY, width - padding, currentY);
                                }
                                else
                                {
                                    g.DrawLine(pen, padding, currentY, width - padding, currentY);
                                }
                                currentY += 14;
                                continue;
                            }

                            if (item.type == "meta_row")
                            {
                                string label = item.left ?? "";
                                string val = item.right ?? "";

                                bool labelHasUnicode = Regex.IsMatch(label, @"[\u1000-\u109F]");
                                bool valHasUnicode = Regex.IsMatch(val, @"[\u1000-\u109F]");
                                string fontFL = labelHasUnicode ? fontFamilyMyanmar : fontFamilyLatin;
                                string fontFV = valHasUnicode ? fontFamilyMyanmar : fontFamilyLatin;

                                using (Font fontL = new Font(fontFL, 17, item.is_bold ? FontStyle.Bold : FontStyle.Regular))
                                using (Font fontV = new Font(fontFV, 17, item.is_bold ? FontStyle.Bold : FontStyle.Regular))
                                {
                                    float colonX = padding + (is58mm ? 90 : 120);
                                    g.DrawString(label, fontL, brush, padding, currentY);
                                    g.DrawString(":", fontL, brush, colonX, currentY);
                                    g.DrawString(val, fontV, brush, colonX + 15, currentY);
                                }

                                currentY += 36;
                                continue;
                            }

                            if (item.type == "receipt_row")
                            {
                                string name = item.item_name ?? item.left ?? "";
                                string q = item.qty ?? "";
                                string p = item.price ?? "";
                                string amt = item.amount ?? item.right ?? "";

                                bool nameHasUnicode = Regex.IsMatch(name, @"[\u1000-\u109F]");
                                string fontF = nameHasUnicode ? fontFamilyMyanmar : fontFamilyLatin;
                                FontStyle fStyle = item.is_bold ? FontStyle.Bold : FontStyle.Regular;

                                int fSize = item.font_size > 0 ? item.font_size : 17;

                                using (Font fontItem = new Font(fontF, fSize, fStyle))
                                using (Font fontNum = new Font(fontFamilyLatin, fSize, fStyle))
                                {
                                    float col1W = is58mm ? 140 : 220;
                                    float col2W = is58mm ? 45 : 65;
                                    float col3W = is58mm ? 75 : 105;
                                    float col4W = is58mm ? 90 : 126;

                                    float x1 = padding;
                                    float x2 = padding + col1W;
                                    float x3 = x2 + col2W;
                                    float x4 = x3 + col3W;

                                    // Item Name (Left aligned)
                                    g.DrawString(name, fontItem, brush, x1, currentY);

                                    // QTY (Right aligned)
                                    if (!string.IsNullOrEmpty(q))
                                    {
                                        SizeF sizeQ = g.MeasureString(q, fontNum);
                                        g.DrawString(q, fontNum, brush, x2 + col2W - sizeQ.Width, currentY);
                                    }

                                    // PRICE (Right aligned)
                                    if (!string.IsNullOrEmpty(p))
                                    {
                                        SizeF sizeP = g.MeasureString(p, fontNum);
                                        g.DrawString(p, fontNum, brush, x3 + col3W - sizeP.Width, currentY);
                                    }

                                    // AMOUNT (Right aligned)
                                    if (!string.IsNullOrEmpty(amt))
                                    {
                                        SizeF sizeA = g.MeasureString(amt, fontNum);
                                        g.DrawString(amt, fontNum, brush, x4 + col4W - sizeA.Width, currentY);
                                    }
                                }

                                currentY += 40;
                                continue;
                            }

                            if (item.type == "item_row")
                            {
                                string leftText = item.left ?? "";
                                string rightText = item.right ?? "";

                                bool leftHasUnicode = Regex.IsMatch(leftText, @"[\u1000-\u109F]");
                                string fontF = leftHasUnicode ? fontFamilyMyanmar : fontFamilyLatin;
                                FontStyle fStyle = item.is_bold ? FontStyle.Bold : FontStyle.Regular;
                                int fSize = item.font_size > 0 ? item.font_size : 17;

                                using (Font fontL = new Font(fontF, fSize, fStyle))
                                using (Font fontR = new Font(fontFamilyLatin, fSize, fStyle))
                                {
                                    g.DrawString(leftText, fontL, brush, padding, currentY);

                                    if (!string.IsNullOrEmpty(rightText))
                                    {
                                        SizeF sizeR = g.MeasureString(rightText, fontR);
                                        float rx = width - padding - sizeR.Width;
                                        g.DrawString(rightText, fontR, brush, rx, currentY);
                                    }
                                }

                                currentY += 40;
                                continue;
                            }

                            if (item.type == "text")
                            {
                                if (item.text != null && item.text.StartsWith("[OUTLET_LOGO:"))
                                {
                                    string logoPath = item.text.Replace("[OUTLET_LOGO:", "").Replace("]", "").Trim();
                                    try
                                    {
                                        Image img = null;
                                        if (File.Exists(logoPath))
                                        {
                                            img = Image.FromFile(logoPath);
                                        }
                                        else if (logoPath.StartsWith("http://", StringComparison.OrdinalIgnoreCase) || logoPath.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
                                        {
                                            using (System.Net.WebClient client = new System.Net.WebClient())
                                            {
                                                byte[] imgBytes = client.DownloadData(logoPath);
                                                using (MemoryStream ms = new MemoryStream(imgBytes))
                                                {
                                                    img = Image.FromStream(ms);
                                                }
                                            }
                                        }

                                        if (img != null)
                                        {
                                            using (img)
                                            {
                                                int logoW = Math.Min(160, img.Width);
                                                int logoH = (int)((float)img.Height * logoW / img.Width);
                                                float logoX = (width - logoW) / 2;
                                                g.DrawImage(img, logoX, currentY, logoW, logoH);
                                                currentY += logoH + 10;
                                            }
                                        }
                                    }
                                    catch (Exception ex)
                                    {
                                        Console.Error.WriteLine("Logo error: " + ex.Message);
                                    }
                                    continue;
                                }

                                int fSize = item.font_size > 0 ? item.font_size : 17;
                                FontStyle style = item.is_bold ? FontStyle.Bold : FontStyle.Regular;

                                bool hasUnicode = Regex.IsMatch(item.text ?? "", @"[\u1000-\u109F]");
                                string fontFamily = hasUnicode ? fontFamilyMyanmar : fontFamilyLatin;

                                using (Font font = new Font(fontFamily, fSize, style))
                                {
                                    float tx = padding;
                                    if (item.is_center)
                                    {
                                        SizeF sizeT = g.MeasureString(item.text ?? "", font);
                                        tx = Math.Max(padding, (int)((width - sizeT.Width) / 2));
                                    }
                                    else if (item.is_indented)
                                    {
                                        tx = padding + 24;
                                    }

                                    g.DrawString(item.text ?? "", font, brush, tx, currentY);
                                    currentY += (fSize + 16);
                                }
                            }
                        }
                    }
                }

                byte[] escPosBytes = BitmapToEscPosRaster(bmp);

                if (args.Length > 1)
                {
                    File.WriteAllBytes(args[1], escPosBytes);
                }
                else
                {
                    using (Stream stdout = Console.OpenStandardOutput())
                    {
                        stdout.Write(escPosBytes, 0, escPosBytes.Length);
                        stdout.Flush();
                    }
                }
            }
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine(ex.Message);
        }
    }

    private static byte[] BitmapToEscPosRaster(Bitmap bmp)
    {
        int width = bmp.Width;
        int totalHeight = bmp.Height;
        int nL = width & 0xFF;
        int nH = (width >> 8) & 0xFF;

        using (MemoryStream ms = new MemoryStream())
        {
            byte[] init = new byte[] { 0x1B, 0x40, 0x1B, 0x33, 0x18 };
            ms.Write(init, 0, init.Length);

            for (int y = 0; y < totalHeight; y += 24)
            {
                byte[] header = new byte[] { 0x1B, 0x2A, 0x21, (byte)nL, (byte)nH };
                ms.Write(header, 0, header.Length);

                for (int x = 0; x < width; x++)
                {
                    for (int k = 0; k < 3; k++)
                    {
                        byte b = 0;
                        for (int bit = 0; bit < 8; bit++)
                        {
                            int py = y + (k * 8) + bit;
                            if (py < totalHeight)
                            {
                                Color pixel = bmp.GetPixel(x, py);
                                int luminance = (int)(pixel.R * 0.299 + pixel.G * 0.587 + pixel.B * 0.114);
                                if (luminance < 160)
                                {
                                    b |= (byte)(1 << (7 - bit));
                                }
                            }
                        }
                        ms.WriteByte(b);
                    }
                }
                ms.WriteByte(0x0A);
            }

            byte[] trailer = new byte[] { 0x1B, 0x32, 0x0A, 0x0A, 0x0A, 0x0A, 0x1D, 0x56, 0x00 };
            ms.Write(trailer, 0, trailer.Length);

            return ms.ToArray();
        }
    }
}
