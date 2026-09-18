# Sessionnet Duisburg Ratsinformation - RSS Feed Adapter

A PHP script that fetches latest documents and their consultations from [Sessionnet Duisburg](https://sessionnet.owl-it.de/duisburg/bi/) and converts them into an **Atom feed** for easy integration with RSS readers or other applications.

---

## **Features**

- Fetches documents and consultations from Sessionnet Duisburg.
- Converts the data into a structured **Atom feed**. 
- Supports configurable `max-entries` via URL parameter (default: 20).
- Handles text cleaning, URL normalization, and metadata extraction.
- **Caching**: File-based cache in `sys_get_temp_dir()/sessionnet_duisburg_cache` with:
  - 15-minute TTL for the main list page.
  - 24-hour TTL for detail and "Beratungen" pages.
  - Falls back to stale cache on fetch failures.
  - Supports `?no-cache=1` to force a fresh fetch.

---

## **Usage**

1. **Access the Script**:
  - Directly call the script in your browser or RSS reader:
  - Optionally, limit the number of entries:
    ```
    /server.php?max-entries=10
    ```
  - Force a fresh fetch (bypassing the cache):
    ```
    /server.php?no-cache=1
    ```
2. **Output**:
  - The script returns an **Atom feed** in XML format.

---

## **Requirements**

- PHP 7.4+ (or a compatible version with `DOMDocument`, `cURL`, and `DateTime` support).
- cURL extension enabled.
- Access to the Sessionnet Duisburg website.

---

### **Docker Setup (Recommended)**

Use the provided `compose.yaml` to run the script alongside **FreshRSS** in Docker.

1. **Prerequisites**:
  - Docker and Docker Compose installed.
  - A `.env` file with the required environment variables (e.g., `BASE_URL`, `ADMIN_API_PASSWORD`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`).
2. **Start the Services**:
  ```bash
   docker compose up -d
  ```

   FreshRSS will be available at `http://127.0.0.1:8081`.  
   The adapter runs internally on port `3457` (not exposed externally).
3. **Add the Feed to FreshRSS**:  
 In FreshRSS, add a new feed with the URL:
  ```
   http://freshrss-sessionnet:3457/server.php
  ```

---

## **Configuration**

- **Base URL**: `https://sessionnet.owl-it.de/duisburg/bi/`
- **Feed Title**: `Ratsinformation Duisburg`
- **Max Entries**: Adjustable via `?max-entries=N` (default: 20).

---

## **Old and Basic FreshRSS Extraction (HTML + XPath)**

For reference, here is the legacy FreshRSS extraction configuration:

- **Feed URL**: `https://sessionnet.owl-it.de/duisburg/bi/do0040.asp`
- **Feed Title**: `"Ratsinformation Duisburg"`
- **Finding News Items**: `//tr[@class="smc-t-r-l"]`
- **Item Title**: `substring-after(descendant::td[contains(@class,"dovorgang")]/a/@title, ": ")`
- **Item Content**: `descendant::td[@class="smc-t-cl991 xxdocs"]`
- **Item Link (URL)**: `descendant::td[@class="smc-t-cl991 dovorgang"]/a/@href`
- **Item Date**: `descendant::ul[contains(@class,"smc-detail-list")]/li[1]`
- **Item Tags**: `descendant::td[@class="smc-t-cl991 doart"]`