# Sessionnet Duisburg Ratsinformation - RSS Feed Adapter

A **PHP script** that fetches the latest documents and consultations from [Sessionnet Duisburg](https://sessionnet.owl-it.de/duisburg/bi/) and converts them into an **Atom feed** for seamless integration with RSS readers or other applications.

---

## ✨ **Features**

- **Data Fetching**: Automatically retrieves documents and consultations from Sessionnet Duisburg.
- **Atom Feed Generation**: Converts data into a structured, standards-compliant **Atom feed**.
- **Configurable Limits**: Set `max-entries` via URL parameter (default: `20`).
- **Intelligent Processing**: Handles text cleaning, URL normalization, and metadata extraction.
- **Robust Caching**:
  - File-based cache with automatic storage in `sys_get_temp_dir()/sessionnet_duisburg_cache`.
  - Smart TTL: 15 minutes for the main list page, 24 hours for detail and "Beratungen" pages.
  - Graceful fallback to stale cache on fetch failures.
  - Manual cache bypass with `?no-cache=1`.

---

## 📋 **Requirements**

- **PHP 7.4+** (with `DOMDocument`, `cURL`, and `DateTime` support).
- **cURL extension** enabled.
- **Network access** to [Sessionnet Duisburg](https://sessionnet.owl-it.de/duisburg/bi/).

---

## 🚀 **Getting Started**

### **Option 1: Direct Usage**

1. **Deploy** the script to your PHP-enabled web server.
2. **Access** the feed directly:
  ```bash
   curl http://your-server.com/server.php
  ```

**URL Parameters:**


| Parameter     | Description                     | Example                     |
| ------------- | ------------------------------- | --------------------------- |
| `max-entries` | Limit the number of feed items. | `server.php?max-entries=10` |
| `no-cache`    | Force a fresh fetch.            | `server.php?no-cache=1`     |


**Output:** The script returns an **Atom feed** in XML format.

---

### **Option 2: Docker Setup (Recommended)**

For integration with **FreshRSS**:

1. **Clone** the repository.
2. **Create** a `.env` file with the required environment variables
3. **Start** the services:
  ```bash
   docker compose up -d
  ```
4. **Access FreshRSS** at: `http://127.0.0.1:8081`.
5. **Add the feed** to FreshRSS using the URL:
  ```
   http://freshrss-sessionnet:3457/server.php
  ```

---

## ⚙️ **Configuration**


| Variable      | Description                            | Default                                     |
| ------------- | -------------------------------------- | ------------------------------------------- |
| `BASE_URL`    | Base URL for Sessionnet Duisburg.      | `https://sessionnet.owl-it.de/duisburg/bi/` |
| `FEED_TITLE`  | Title of the generated Atom feed.      | `Ratsinformation Duisburg`                  |
| `max-entries` | Maximum number of entries in the feed. | `20` (adjustable via URL parameter)         |


---

## 🔧 **Technical Details**

- **HTTP Caching**: Supports ETag and Last-Modified headers for efficient polling by RSS readers.
- **Error Handling**: Gracefully falls back to stale cache data on fetch failures.
- **XML Validation**: The generated Atom feed is validated before output to ensure well-formed XML.

---

## 📜 **Legacy FreshRSS Extraction (HTML + XPath)**

For reference, here is the legacy FreshRSS extraction configuration:


| Field              | XPath Query                                                                    |
| ------------------ | ------------------------------------------------------------------------------ |
| Feed URL           | `https://sessionnet.owl-it.de/duisburg/bi/do0040.asp`                          |
| Feed Title         | `"Ratsinformation Duisburg"`                                                   |
| Finding News Items | `//tr[@class="smc-t-r-l"]`                                                     |
| Item Title         | `substring-after(descendant::td[contains(@class,"dovorgang")]/a/@title, ": ")` |
| Item Content       | `descendant::td[@class="smc-t-cl991 xxdocs"]`                                  |
| Item Link (URL)    | `descendant::td[@class="smc-t-cl991 dovorgang"]/a/@href`                       |
| Item Date          | `descendant::ul[contains(@class,"smc-detail-list")]/li[1]`                     |
| Item Tags          | `descendant::td[@class="smc-t-cl991 doart"]`                                   |