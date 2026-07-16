# Kevinz Dowloader — deploy lên Render

## Cách 1 — dùng render.yaml (nhanh nhất)
1. Push 4 file này (`index.php`, `Dockerfile`, `render.yaml`, `README.md`) lên một repo GitHub.
2. Vào Render → **New +** → **Blueprint** → chọn repo đó. Render tự đọc `render.yaml` và deploy.

## Cách 2 — thủ công
1. Push repo lên GitHub.
2. Render → **New +** → **Web Service** → chọn repo.
3. Environment: **Docker** (Render tự nhận diện `Dockerfile`).
4. Plan **Free** là đủ chạy thử; nếu tool được dùng nhiều, nên nâng lên plan trả phí vì free tier sẽ "ngủ" sau vài phút không có traffic (request đầu tiên sau khi ngủ sẽ chậm ~30-50s).
5. Deploy — xong.

## Những gì đã sửa/nâng cấp so với bản gốc
- **Fix lỗi không xem/nghe được**: nguyên nhân là CDN của TikTok/Instagram/Pinterest/Spotify chặn hotlink theo header `Referer`. File giờ có endpoint `?proxy=` tự stream media qua server (kèm header giả lập đúng), nên nút ▶ Xem / ▶ Nghe và nút Tải đều chạy qua domain của chính bạn — không còn bị chặn.
- Nút bấm không còn build bằng cách nhét thẳng URL vào `onclick="..."` (dễ vỡ khi URL có `'`, `&`, ký tự đặc biệt) → chuyển sang `data-*` + event delegation, an toàn hơn và không còn bug "bấm không ăn".
- Thêm timeout cho mọi cuộc gọi cURL ở backend (8-20s) để tránh treo request vô thời hạn khi API nguồn die.
- Thêm trạng thái loading / lỗi / thành công rõ ràng (thay `alert()` bằng toast + status bar), nút Execute tự disable khi đang chạy, huỷ request cũ nếu bấm tìm lần nữa (AbortController).
- Escape toàn bộ text/URL trước khi chèn vào DOM (chống XSS từ dữ liệu API bên thứ ba).
- Responsive thật ở 320/375/414/768px: nút không còn xuống 2 dòng, ảnh không tràn, grid co giãn đúng.
- Vẫn giữ nguyên giao diện Windows 98 (98.css) — chỉ bổ sung lớp token CSS riêng cho spinner/toast/trạng thái, không đụng vào theme gốc.

## Giới hạn cần biết
- Các API nguồn (`puruboy-api.vercel.app`, `xsaver.io`, `vdfr.app`, `spotmate.online`, `pin.vinayop.cloud`) là dịch vụ bên thứ ba, không do bạn kiểm soát — nếu họ đổi cấu trúc phản hồi hoặc sập, luồng tương ứng sẽ báo lỗi (đã có thông báo rõ thay vì im lặng).
- Render free tier có giới hạn băng thông/CPU — nếu proxy stream video dung lượng lớn thường xuyên, cân nhắc gói trả phí.
