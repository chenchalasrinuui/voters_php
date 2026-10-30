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
  const origin = req.headers.origin;
  if (origin) {
    res.setHeader('Access-Control-Allow-Origin', origin);
    res.setHeader('Access-Control-Allow-Credentials', 'true');
  } else {
    res.setHeader('Access-Control-Allow-Origin', '*');
  }
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
  res.setHeader(
    'Access-Control-Allow-Headers',
    'Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-User-Id, X-User-Role, X-User-Numeric-Id'
  );
  res.setHeader('Access-Control-Max-Age', '86400');

  if (req.method === 'OPTIONS') {
    res.status(204).end();
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

// Authentication Endpoint (matches PHP backend)
const handleAuth = (req: Request, res: Response) => {
  const username = (req.body.username || req.body.phone || req.body.email || '').toString().trim();
  const password = (req.body.password || '').toString().trim();

  if (!username || !password) {
    res.status(400).json({ error: 'Username and Password required' });
    return;
  }

  res.json({
    success: true,
    role: username === 'admin' ? 'admin' : 'user',
    token: 'mock-jwt-token-2026',
    user: {
      id: 1,
      username,
      name: username === 'admin' ? 'Chief Election Administrator' : 'శ్రీను చెంచల (Srinu Chenchala)',
      village: 'కొమ్మువలస (Kommuvalasa)',
      role: username === 'admin' ? 'admin' : 'user',
    },
  });
};

app.post(['/api/auth/login', '/api/auth/login/', '/voters_php/api/auth/login', '/voters_php/api/auth/login/'], handleAuth);
app.get(['/api/health', '/voters_php/api/health'], (req, res) => {
  res.json({ status: 'ok', cors: 'enabled' });
});

app.listen(Number(PORT), '0.0.0.0', () => {
  console.log(`[Voters Dev Server] Active on port ${PORT}`);
});
