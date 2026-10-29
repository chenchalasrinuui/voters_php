import express, { Request, Response, NextFunction } from 'express';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import jwt from 'jsonwebtoken';
import multer from 'multer';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
const PORT = process.env.PORT || 3000;
const JWT_SECRET = process.env.JWT_SECRET || 'voters_election_secure_jwt_key_2026';

// ----------------------------------------------------
// 1. Comprehensive CORS Middleware
// Supports requests from http://localhost:3000, 5173, XAMPP, and all origins
// ----------------------------------------------------
app.use((req: Request, res: Response, next: NextFunction) => {
  const origin = req.headers.origin;
  if (origin) {
    res.setHeader('Access-Control-Allow-Origin', origin);
    res.setHeader('Access-Control-Allow-Credentials', 'true');
  } else {
    res.setHeader('Access-Control-Allow-Origin', '*');
  }
  res.setHeader(
    'Access-Control-Allow-Methods',
    'GET, POST, PUT, PATCH, DELETE, OPTIONS'
  );
  res.setHeader(
    'Access-Control-Allow-Headers',
    'Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-User-Id, X-User-Role, X-User-Numeric-Id'
  );
  res.setHeader(
    'Access-Control-Expose-Headers',
    'Content-Length, Content-Type, Authorization'
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

// Upload directory setup
const uploadsDir = path.join(__dirname, 'uploads');
if (!fs.existsSync(uploadsDir)) {
  fs.mkdirSync(uploadsDir, { recursive: true });
}
app.use('/uploads', express.static(uploadsDir));

// Serve Swagger static files & UI
const swaggerDir = path.join(__dirname, 'swagger');
app.use('/swagger', express.static(swaggerDir));
app.use('/docs', express.static(swaggerDir));

// In-Memory Models
interface AdminUser {
  id: number;
  username: string;
  password: string;
  name: string;
  role: 'admin';
}

interface AppUser {
  id: number;
  username: string;
  password: string;
  name: string;
  state: string;
  district: string;
  constituency: string;
  mandal: string;
  village: string;
  role: 'user';
  status: 'active' | 'inactive';
  created_at: string;
}

const admins: AdminUser[] = [
  {
    id: 1,
    username: 'admin',
    password: 'password', // Also accepts admin123
    name: 'Chief Election Administrator',
    role: 'admin',
  },
];

const users: AppUser[] = [
  {
    id: 1,
    username: 'srikakulam_agent',
    password: 'password123',
    name: 'శ్రీను చెంచల (Srinu Chenchala)',
    state: 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)',
    district: 'శ్రీకాకుళం (Srikakulam)',
    constituency: '4 - పాతపట్నం (Patapatnam)',
    mandal: 'ఎల్ ఎన్ పేట (L.N. Peta)',
    village: 'కొమ్మువలస (Kommuvalasa)',
    role: 'user',
    status: 'active',
    created_at: '2026-01-15 09:30:00',
  },
];

let villages = [
  {
    id: '1',
    numeric_id: 1,
    user_id: 1,
    village_name: 'కొమ్మువలస (Kommuvalasa)',
    village_name_te: 'కొమ్మువలస',
    state: 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)',
    district: 'శ్రీకాకుళం (Srikakulam)',
    constituency: '4 - పాతపట్నం (Patapatnam)',
    mandal: 'ఎల్ ఎన్ పేట (L.N. Peta)',
    booth_name: 'కొమ్మువలస మండల పరిషత్ ప్రాథమిక పాఠశాల (బూత్ 1)',
    part_no: '1',
    pdf_filename: 'KOMMUVALASA_NEW_VOTER_WARD_LIST_2026.pdf',
    isDefault: true,
  },
];

let voters = [
  {
    id: 1,
    village_id: 1,
    sl_no: 1,
    slNo: 1,
    epic: 'APZ1984210',
    name: 'చెంచల రాముడు',
    name_en: 'Chenchala Ramudu',
    nameEn: 'Chenchala Ramudu',
    relation_type: 'Father',
    relationType: 'Father',
    relation_name: 'చెంచల అప్పన్న',
    relationName: 'చెంచల అప్పన్న',
    house_no: '1-12',
    houseNo: '1-12',
    age: 48,
    gender: 'Male',
    ward_no: '1',
    wardNo: '1',
    is_voted: 0,
    votingStatus: 'NOT_VOTED',
    flag_color: 'none',
    flagColor: 'none',
  },
  {
    id: 2,
    village_id: 1,
    sl_no: 2,
    slNo: 2,
    epic: 'APZ1984228',
    name: 'చెంచల లక్ష్మి',
    name_en: 'Chenchala Lakshmi',
    nameEn: 'Chenchala Lakshmi',
    relation_type: 'Husband',
    relationType: 'Husband',
    relation_name: 'చెంచల రాముడు',
    relationName: 'చెంచల రాముడు',
    house_no: '1-12',
    houseNo: '1-12',
    age: 44,
    gender: 'Female',
    ward_no: '1',
    wardNo: '1',
    is_voted: 0,
    votingStatus: 'NOT_VOTED',
    flag_color: 'none',
    flagColor: 'none',
  },
];

// ----------------------------------------------------
// 2. Routes (Matching all PHP routing endpoints)
// ----------------------------------------------------

// Swagger UI at root
app.get('/', (req, res) => {
  res.sendFile(path.join(swaggerDir, 'index.html'));
});

// Health Check
app.get(['/api/health', '/voters_php/api/health'], (req, res) => {
  res.json({
    status: 'ok',
    backend: 'node-express',
    version: process.version,
    cors: 'enabled',
    timestamp: new Date().toISOString(),
  });
});

// Authentication Handler (Handles both /api/auth/login, /api/auth, /voters_php/api/auth/login)
const handleAuth = (req: Request, res: Response) => {
  const username = (req.body.username || req.body.phone || req.body.email || '').toString().trim();
  const password = (req.body.password || '').toString().trim();

  if (!username || !password) {
    res.status(400).json({
      error: 'యూజర్‌నేమ్ మరియు పాస్‌వర్డ్ నమోదు చేయండి (Username and Password required)',
    });
    return;
  }

  // 1. Admin Login
  const admin = admins.find(a => a.username === username);
  if (admin && (admin.password === password || password === 'admin123')) {
    const token = jwt.sign({ userId: admin.id, role: 'admin' }, JWT_SECRET, { expiresIn: '24h' });
    res.json({
      success: true,
      role: 'admin',
      token,
      user: {
        id: admin.id,
        username: admin.username,
        name: admin.name,
        role: 'admin',
      },
    });
    return;
  }

  // 2. User Login
  const user = users.find(u => u.username === username);
  if (user && user.password === password) {
    if (user.status !== 'active') {
      res.status(403).json({
        error: 'మీ ఖాతా నిలిపివేయబడింది (Your account has been deactivated by administrator)',
      });
      return;
    }

    const token = jwt.sign({ userId: user.id, role: user.role }, JWT_SECRET, { expiresIn: '24h' });
    res.json({
      success: true,
      role: user.role,
      token,
      user: {
        id: user.id,
        username: user.username,
        name: user.name,
        state: user.state,
        district: user.district,
        constituency: user.constituency,
        mandal: user.mandal,
        village: user.village,
        role: user.role,
      },
    });
    return;
  }

  res.status(401).json({
    error: 'చెల్లని యూజర్‌నేమ్ లేదా పాస్‌వర్డ్ (Invalid username or password)',
  });
};

// GET info handler
const handleAuthGet = (req: Request, res: Response) => {
  res.json({
    status: 'active',
    endpoint: '/api/auth/login',
    method: 'POST',
    message: 'Auth login route is ready. Send a POST request with JSON body { username, password }.',
    cors: 'enabled',
  });
};

app.get(['/api/auth/login', '/voters_php/api/auth/login'], handleAuthGet);
app.post('/api/auth/login', handleAuth);
app.post('/api/auth', handleAuth);
app.post('/voters_php/api/auth/login', handleAuth);
app.post('/voters_php/api/auth', handleAuth);
app.post('/backend/auth.php', handleAuth);

// Admin Stats
app.get(['/api/admin/stats', '/voters_php/api/admin/stats'], (req, res) => {
  const totalUsers = users.length;
  const totalVillages = villages.length;
  const totalVoters = voters.length;
  const totalVoted = voters.filter(v => v.is_voted === 1).length;
  const turnoutPercentage = totalVoters > 0 ? ((totalVoted / totalVoters) * 100).toFixed(1) : '0';

  res.json({
    totalUsers,
    totalVillages,
    totalVoters,
    totalVoted,
    turnoutPercentage,
  });
});

// Admin Users
app.get(['/api/admin/users', '/api/admin', '/voters_php/api/admin/users'], (req, res) => {
  res.json(users);
});

// Villages
app.get(['/api/villages', '/voters_php/api/villages'], (req, res) => {
  res.json(villages);
});

// Voters
app.get(['/api/voters', '/voters_php/api/voters'], (req, res) => {
  res.json(voters);
});

// Mark Voter as Voted
app.post(['/api/voters/vote', '/voters_php/api/voters/vote'], (req, res) => {
  const voterId = req.body.voterId || req.body.id;
  const voter = voters.find(v => v.id === parseInt(voterId, 10));
  if (!voter) {
    res.status(404).json({ error: 'Voter not found' });
    return;
  }
  voter.is_voted = voter.is_voted === 1 ? 0 : 1;
  voter.votingStatus = voter.is_voted === 1 ? 'VOTED' : 'NOT_VOTED';
  res.json({
    success: true,
    is_voted: voter.is_voted,
    votingStatus: voter.votingStatus,
  });
});

// Voter Flags
app.post(['/api/voters/flag', '/voters_php/api/voters/flag'], (req, res) => {
  const voterId = req.body.voterId || req.body.id;
  const flagColor = (req.body.flagColor || req.body.flag_color || 'none').toLowerCase();
  const voter = voters.find(v => v.id === parseInt(voterId, 10));
  if (!voter) {
    res.status(404).json({ error: 'Voter not found' });
    return;
  }
  voter.flag_color = flagColor;
  voter.flagColor = flagColor;
  res.json({ success: true, flag_color: flagColor });
});

app.listen(Number(PORT), '0.0.0.0', () => {
  console.log(`[Voters API] Server running on http://0.0.0.0:${PORT}`);
  console.log(`[Swagger Docs] http://0.0.0.0:${PORT}/swagger`);
});
