import express, { Request, Response, NextFunction } from 'express';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
const PORT = process.env.PORT || 3000;

// Universal CORS Middleware
app.use((req: Request, res: Response, next: NextFunction) => {
  const origin = req.headers.origin || 'http://localhost:3000';
  res.setHeader('Access-Control-Allow-Origin', origin);
  res.setHeader('Access-Control-Allow-Credentials', 'true');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
  res.setHeader(
    'Access-Control-Allow-Headers',
    'Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-User-Id, X-User-Role, X-User-Numeric-Id, *'
  );
  res.setHeader('Access-Control-Max-Age', '86400');

  if (req.method === 'OPTIONS') {
    res.status(200).end();
    return;
  }
  next();
});

app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Serve Swagger UI
const swaggerDir = path.join(__dirname, 'swagger');
app.use('/swagger', express.static(swaggerDir));
app.get('/', (req, res) => {
  res.sendFile(path.join(swaggerDir, 'index.html'));
});

// Authentication Endpoint (mirrors real database: admin/admin123 and srinu/srinu123)
const handleAuth = (req: Request, res: Response) => {
  const username = (
    req.body.username ||
    req.body.userId ||
    req.body.user_id ||
    req.body.userid ||
    req.body.user ||
    req.body.id ||
    ''
  ).toString().trim();

  const password = (
    req.body.password ||
    req.body.pwd ||
    req.body.pass ||
    ''
  ).toString().trim();

  if (!username || !password) {
    res.status(400).json({ error: 'User ID / Username and Password required' });
    return;
  }

  if (username === 'admin' && (password === 'admin123' || password === 'password')) {
    res.json({
      success: true,
      role: 'admin',
      token: 'admin_token_' + Date.now(),
      user: {
        id: 1,
        username: 'admin',
        userId: 'admin',
        name: 'Chief Election Administrator',
        role: 'admin',
      },
    });
    return;
  }

  if (username === 'srinu' && (password === 'srinu123' || password === 'password')) {
    res.json({
      success: true,
      role: 'user',
      token: 'user_token_' + Date.now(),
      user: {
        id: 1,
        username: 'srinu',
        userId: 'srinu',
        name: 'Srinu Chenchala',
        state: 'Andhra Pradesh',
        district: 'Srikakulam',
        constituency: 'Patapatnam',
        mandal: 'L.N. Peta',
        village: 'Kommuvalasa',
        role: 'user',
      },
    });
    return;
  }

  res.status(401).json({
    error: 'Invalid User ID or Password. No matching user found in database.',
  });
};

app.post(['/api/auth/login', '/api/auth/login/', '/voters_php/api/auth/login', '/voters_php/api/auth/login/'], handleAuth);
app.get(['/api/auth/login', '/api/auth/login/', '/voters_php/api/auth/login', '/voters_php/api/auth/login/'], (req, res) => {
  res.json({ status: 'active', message: 'Auth endpoint ready' });
});

// PDF Upload Endpoint
const handleUpload = (req: Request, res: Response) => {
  const village = (req.body.village || req.body.village_name || req.body.name || 'కొమ్మువలస').toString().trim();
  res.json({
    success: true,
    message: `గ్రామం '${village}' ఓటర్ల జాబితా విజయవంతంగా అప్‌లోడ్ చేయబడింది!`,
    village: {
      id: '1',
      name: village,
      name_te: village,
      state: 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)',
      district: 'శ్రీకాకుళం (Srikakulam)',
      constituency: '4 - పాతపట్నం (Patapatnam)',
      mandal: 'ఎల్ ఎన్ పేట (L.N. Peta)',
      booth_name: `${village} పోలింగ్ కేంద్రం`,
      part_no: '1',
      fileName: 'voter_list.pdf',
      voterCount: 0,
      votedCount: 0,
    },
  });
};

app.post(['/api/upload', '/api/upload/', '/voters_php/api/upload', '/voters_php/api/upload/'], handleUpload);
app.get(['/api/upload', '/api/upload/', '/voters_php/api/upload', '/voters_php/api/upload/'], (req, res) => {
  res.json({ status: 'active', endpoint: '/api/upload', method: 'POST', cors: 'enabled' });
});

// Health check
app.get(['/api/health', '/api/health/', '/voters_php/api/health', '/voters_php/api/health/'], (req, res) => {
  res.json({ status: 'ok', cors: 'enabled' });
});

app.listen(Number(PORT), '0.0.0.0', () => {
  console.log(`[Voters Dev Server] Active on port ${PORT}`);
});
