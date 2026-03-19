/**
 * URL Mapper — translates app routes to static HTML file paths
 * and calculates relative paths between pages.
 */

const path = require('path');

class URLMapper {
  /**
   * @param {Array} routes — route definitions from routes.js
   * @param {string} baseUrl — live site base URL (for detecting internal links)
   */
  constructor(routes, baseUrl) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.routeToFileMap = new Map();
    this.fileToRouteMap = new Map();

    for (const route of routes) {
      const htmlPath = this.routeToHTMLPath(route.path, route.queryParams);
      this.routeToFileMap.set(this._normalizeRoute(route.path, route.queryParams), htmlPath);
      this.fileToRouteMap.set(htmlPath, route);
    }
  }

  /**
   * Convert a route path to a static HTML file path.
   *   /                         → pages/dashboard.html
   *   /login                    → pages/login.html
   *   /unauthorized             → pages/unauthorized.html
   *   /modules/employees/index  → pages/modules/employees/index.html
   *   /modules/employees/edit?id=1 → pages/modules/employees/edit-id-1.html
   */
  routeToHTMLPath(routePath, queryParams) {
    let cleanPath = routePath.replace(/^\/+|\/+$/g, '');

    if (!cleanPath || cleanPath === '/') {
      cleanPath = 'dashboard';
    }

    // Flatten query params into filename
    if (queryParams && Object.keys(queryParams).length > 0) {
      const suffix = Object.entries(queryParams)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([k, v]) => `${k}-${v}`)
        .join('-');
      cleanPath += `-${suffix}`;
    }

    return `pages/${cleanPath}.html`;
  }

  /**
   * Check if a URL is an internal app route.
   */
  isAppRoute(url) {
    if (!url || url.startsWith('#') || url.startsWith('javascript:') || url.startsWith('mailto:') || url.startsWith('tel:')) {
      return false;
    }

    try {
      // Strip base URL prefix if present
      let routePath = url;
      if (url.startsWith(this.baseUrl)) {
        routePath = url.slice(this.baseUrl.length);
      } else if (url.startsWith('http://') || url.startsWith('https://')) {
        return false; // External link
      }

      // Remove query string and hash for lookup
      routePath = routePath.split('?')[0].split('#')[0];
      if (!routePath.startsWith('/')) routePath = '/' + routePath;

      // Check if any route matches this path
      for (const [key] of this.routeToFileMap) {
        const keyPath = key.split('?')[0];
        if (keyPath === routePath) return true;
      }

      // Also match asset paths
      if (routePath.startsWith('/assets/') || routePath.startsWith('/modules/') || routePath === '/' || routePath === '/login' || routePath === '/unauthorized') {
        return true;
      }

      return false;
    } catch {
      return false;
    }
  }

  /**
   * Rewrite an internal URL to a relative path from the current page.
   * @param {string} url — the URL to rewrite
   * @param {string} currentPageHTMLPath — the HTML path of the page containing this link
   * @returns {string} relative path to the target
   */
  rewriteURL(url, currentPageHTMLPath) {
    if (!url || url.startsWith('#') || url.startsWith('javascript:') || url.startsWith('mailto:') || url.startsWith('tel:') || url.startsWith('data:')) {
      return url;
    }

    // Strip base URL
    let routePath = url;
    if (url.startsWith(this.baseUrl)) {
      routePath = url.slice(this.baseUrl.length);
    }

    // Handle asset paths
    if (routePath.startsWith('/assets/')) {
      const assetRelPath = routePath.slice(1); // Remove leading /
      return this.relativePath(currentPageHTMLPath, assetRelPath);
    }

    // Parse route path and query
    const [pathPart, queryPart] = routePath.split('?');
    const cleanRoutePath = pathPart.startsWith('/') ? pathPart : '/' + pathPart;

    // Try to find matching query params from the URL
    let queryParams = null;
    if (queryPart) {
      queryParams = {};
      new URLSearchParams(queryPart).forEach((v, k) => { queryParams[k] = v; });
    }

    // Look up the HTML file path
    const normalized = this._normalizeRoute(cleanRoutePath, queryParams);
    let targetHTMLPath = this.routeToFileMap.get(normalized);

    if (!targetHTMLPath) {
      // Fallback: try without query params
      const noQueryNorm = this._normalizeRoute(cleanRoutePath, null);
      targetHTMLPath = this.routeToFileMap.get(noQueryNorm);
    }

    if (!targetHTMLPath) {
      // Unknown route — build a best-guess path
      targetHTMLPath = this.routeToHTMLPath(cleanRoutePath, queryParams);
    }

    return this.relativePath(currentPageHTMLPath, targetHTMLPath);
  }

  /**
   * Calculate relative path from one file to another within the output directory.
   *   from: pages/modules/employees/index.html
   *   to:   pages/modules/payroll/index.html
   *   → ../../payroll/index.html (relative from the directory of `from`)
   */
  relativePath(fromHTMLPath, toHTMLPath) {
    const fromDir = path.dirname(fromHTMLPath);
    let rel = path.relative(fromDir, toHTMLPath).replace(/\\/g, '/');
    if (!rel.startsWith('.')) rel = './' + rel;
    return rel;
  }

  _normalizeRoute(routePath, queryParams) {
    let norm = routePath.replace(/\/+$/, '');
    if (!norm.startsWith('/')) norm = '/' + norm;
    if (queryParams && Object.keys(queryParams).length > 0) {
      const qs = Object.entries(queryParams)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([k, v]) => `${k}=${v}`)
        .join('&');
      norm += '?' + qs;
    }
    return norm;
  }
}

module.exports = URLMapper;
