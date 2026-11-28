const fs = require('fs');
const path = require('path');
const yaml = require('js-yaml');

const COLLECTION_DIR = path.join(__dirname, 'docs/postman/Organic Farm N9 - Đăng nhập, đăng ký, phân quyền');
const OUTPUT_FILE = path.join(__dirname, 'docs/postman/Organic Farm N9 - Đăng nhập, đăng ký, phân quyền.json');

function readYaml(filePath) {
  const content = fs.readFileSync(filePath, 'utf8');
  return yaml.load(content);
}

function buildAuth(auth) {
  if (!auth) return undefined;
  const type = auth.type;
  let creds = auth.credentials || [];
  // credentials can be an object {key: value} or array [{key, value}]
  if (!Array.isArray(creds)) {
    creds = Object.entries(creds).map(([k, v]) => ({ key: k, value: v }));
  }
  const result = { type };
  if (type === 'bearer') {
    result.bearer = creds.map(c => ({ key: c.key, value: String(c.value || ''), type: 'string' }));
  } else if (type === 'basic') {
    result.basic = creds.map(c => ({ key: c.key, value: String(c.value || ''), type: 'string' }));
  } else if (type === 'apikey') {
    result.apikey = creds.map(c => ({ key: c.key, value: String(c.value || ''), type: 'string' }));
  }
  return result;
}

function buildUrl(urlStr, queryParams, pathVariables) {
  if (!urlStr) return { raw: '' };
  const urlObj = { raw: urlStr };

  // Parse host and path
  try {
    // Handle variables like {{base_url}}/path
    const rawUrl = urlStr;
    // Split protocol
    const protoMatch = rawUrl.match(/^(https?):\/\//);
    if (protoMatch) {
      urlObj.protocol = protoMatch[1];
      const rest = rawUrl.slice(protoMatch[0].length);
      const slashIdx = rest.indexOf('/');
      if (slashIdx === -1) {
        urlObj.host = [rest];
        urlObj.path = [];
      } else {
        urlObj.host = [rest.slice(0, slashIdx)];
        const pathPart = rest.slice(slashIdx + 1).split('?')[0];
        urlObj.path = pathPart ? pathPart.split('/') : [];
      }
    } else {
      // Variable-based URL like {{baseUrl}}/api/...
      const slashIdx = rawUrl.indexOf('/');
      if (slashIdx === -1) {
        urlObj.host = [rawUrl];
      } else {
        urlObj.host = [rawUrl.slice(0, slashIdx)];
        const pathPart = rawUrl.slice(slashIdx + 1).split('?')[0];
        urlObj.path = pathPart ? pathPart.split('/') : [];
      }
    }
  } catch (e) {}

  if (queryParams && queryParams.length > 0) {
    urlObj.query = queryParams.map(q => ({
      key: q.key,
      value: q.value || '',
      description: q.description || '',
      disabled: q.disabled || false
    }));
  }

  if (pathVariables && pathVariables.length > 0) {
    urlObj.variable = pathVariables.map(v => ({
      key: v.key,
      value: v.value || '',
      description: v.description || ''
    }));
  }

  return urlObj;
}

function buildBody(body) {
  if (!body || body.type === 'none') return undefined;
  const result = { mode: body.type };
  if (body.type === 'json') {
    result.mode = 'raw';
    result.raw = body.content || '';
    result.options = { raw: { language: 'json' } };
  } else if (body.type === 'raw') {
    result.mode = 'raw';
    result.raw = body.content || '';
  } else if (body.type === 'formdata') {
    result.formdata = (body.content || []).map(f => ({
      key: f.key,
      value: f.value || '',
      type: f.type || 'text',
      description: f.description || ''
    }));
  } else if (body.type === 'urlencoded') {
    result.urlencoded = (body.content || []).map(f => ({
      key: f.key,
      value: f.value || '',
      description: f.description || ''
    }));
  } else {
    result.mode = 'raw';
    result.raw = body.content || '';
  }
  return result;
}

function buildHeaders(headers) {
  if (!headers) return [];
  if (Array.isArray(headers)) {
    return headers.map(h => ({
      key: h.key,
      value: h.value || '',
      description: h.description || '',
      disabled: h.disabled || false
    }));
  }
  // Object form
  return Object.entries(headers).map(([key, value]) => ({ key, value: value || '' }));
}

function buildScripts(scripts) {
  const events = [];
  if (!scripts) return events;
  for (const s of scripts) {
    const type = s.type === 'beforeRequest' ? 'prerequest' : 'test';
    events.push({
      listen: type,
      script: {
        type: 'text/javascript',
        exec: (s.code || '').split('\n')
      }
    });
  }
  return events;
}

function buildRequest(reqYaml, name) {
  const item = {
    name: reqYaml.name || name,
    request: {
      method: reqYaml.method || 'GET',
      header: buildHeaders(reqYaml.headers),
      url: buildUrl(reqYaml.url, reqYaml.queryParams, reqYaml.pathVariables)
    },
    response: []
  };

  if (reqYaml.body && reqYaml.body.type !== 'none') {
    item.request.body = buildBody(reqYaml.body);
  }

  if (reqYaml.auth) {
    item.request.auth = buildAuth(reqYaml.auth);
  }

  if (reqYaml.scripts && reqYaml.scripts.length > 0) {
    item.event = buildScripts(reqYaml.scripts);
  }

  if (reqYaml.description) {
    item.request.description = reqYaml.description;
  }

  return item;
}

function processFolder(folderPath) {
  const items = [];
  const entries = fs.readdirSync(folderPath, { withFileTypes: true });

  // Sort by order if available, then by name
  const requestFiles = [];
  const subFolders = [];

  for (const entry of entries) {
    if (entry.name === '.resources') continue;
    const fullPath = path.join(folderPath, entry.name);
    if (entry.isDirectory()) {
      subFolders.push({ name: entry.name, fullPath });
    } else if (entry.name.endsWith('.request.yaml')) {
      requestFiles.push({ name: entry.name, fullPath });
    }
  }

  // Load requests with order
  const requestItems = requestFiles.map(f => {
    const data = readYaml(f.fullPath);
    const displayName = data.name || f.name.replace('.request.yaml', '');
    return { order: data.order || 9999, item: buildRequest(data, displayName) };
  });
  requestItems.sort((a, b) => a.order - b.order);

  // Load subfolders with order
  const folderItems = subFolders.map(f => {
    const defPath = path.join(f.fullPath, '.resources', 'definition.yaml');
    let folderDef = {};
    if (fs.existsSync(defPath)) {
      folderDef = readYaml(defPath) || {};
    }
    const folderItem = {
      name: folderDef.name || f.name,
      item: processFolder(f.fullPath),
      description: folderDef.description || ''
    };
    if (folderDef.auth) folderItem.auth = buildAuth(folderDef.auth);
    if (folderDef.scripts && folderDef.scripts.length > 0) {
      folderItem.event = buildScripts(folderDef.scripts);
    }
    return { order: folderDef.order || 9999, item: folderItem };
  });
  folderItems.sort((a, b) => a.order - b.order);

  // Combine: folders first then requests (or by order)
  const allItems = [
    ...folderItems.map(f => f.item),
    ...requestItems.map(r => r.item)
  ];

  return allItems;
}

// Read collection definition
const defPath = path.join(COLLECTION_DIR, '.resources', 'definition.yaml');
const def = readYaml(defPath) || {};

const collectionName = def.name || 'Organic Farm N9 - Đăng nhập, đăng ký, phân quyền';

// Build variables
const variables = [];
if (def.variables) {
  for (const [key, value] of Object.entries(def.variables)) {
    variables.push({ key, value: String(value || ''), type: 'string' });
  }
}

// Build collection
const collection = {
  info: {
    _postman_id: 'Bb4FB9B2E6BabA4C0f7cbeD4',
    name: collectionName,
    description: def.description || '',
    schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'
  },
  item: processFolder(COLLECTION_DIR),
  variable: variables
};

if (def.auth) {
  collection.auth = buildAuth(def.auth);
}

if (def.scripts && def.scripts.length > 0) {
  collection.event = buildScripts(def.scripts);
}

// Write output
fs.writeFileSync(OUTPUT_FILE, JSON.stringify(collection, null, 2), 'utf8');
console.log('✅ Exported successfully to:', OUTPUT_FILE);
console.log('Total folders/requests at root:', collection.item.length);
