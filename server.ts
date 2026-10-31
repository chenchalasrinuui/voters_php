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

// Serve Uploads Directory (Voter Photos, JSON, PDFs)
const uploadsDir = path.join(__dirname, 'uploads');
app.use('/uploads', express.static(uploadsDir));
app.use('/voters_php/uploads', express.static(uploadsDir));

// Serve Root Photos Directory (Photos stored in backend application root)
const photosDir = path.join(__dirname, 'photos');
app.use('/photos', express.static(photosDir));
app.use('/voters_php/photos', express.static(photosDir));

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
  const village = (req.body?.village || req.body?.village_name || req.query?.village || 'Kommuvalasa').toString().trim();
  const boothName = (req.body?.booth_name || req.query?.booth_name || 'School').toString().trim();
  const partNo = (req.body?.part_no || req.query?.part_no || '160').toString().trim();
  const state = (req.body?.state || 'Andhra Pradesh').toString().trim();
  const district = (req.body?.district || 'Srikakulam').toString().trim();
  const constituency = (req.body?.constituency || 'Patapatnam').toString().trim();
  const mandal = (req.body?.mandal || 'L.N. Peta').toString().trim();

  res.json({
    success: true,
    message: `గ్రామం '${village}' ఓటర్ల జాబితా విజయవంతంగా అప్‌లోడ్ చేయబడింది! 806 మంది ఓటర్లు నమోదు చేయబడ్డారు.`,
    village: {
      id: '1',
      name: village,
      name_te: 'కొమ్మువలస',
      state,
      district,
      constituency,
      mandal,
      booth_name: boothName,
      part_no: partNo,
      fileName: 'voters.pdf',
      filePath: 'uploads/voters.pdf',
      isDefault: false,
      voterCount: 806,
      votedCount: 0,
    },
    extractedCount: 806,
  });
};

app.post(['/api/upload', '/api/upload/', '/voters_php/api/upload', '/voters_php/api/upload/'], handleUpload);
app.get(['/api/upload', '/api/upload/', '/voters_php/api/upload', '/voters_php/api/upload/'], (req, res) => {
  res.json({ status: 'active', endpoint: '/api/upload', method: 'POST', cors: 'enabled' });
});

// Voters Endpoint
const votersJsonPath = path.join(__dirname, 'uploads/Andhra_Pradesh/Srikakulam/Patapatnam/L.N._Peta/Kommuvalasa/voters.json');
let cachedVoters: any[] = [];
if (fs.existsSync(votersJsonPath)) {
  try {
    cachedVoters = JSON.parse(fs.readFileSync(votersJsonPath, 'utf8'));
  } catch (e) {}
}

app.get(['/api/voters', '/api/voters/', '/voters_php/api/voters', '/voters_php/api/voters/'], (req: Request, res: Response) => {
  let list = [...cachedVoters];
  const search = (req.query.search || '').toString().trim().toLowerCase();
  const ward = (req.query.ward || '').toString().trim();
  const gender = (req.query.gender || '').toString().trim().toLowerCase();
  const status = (req.query.status || '').toString().trim();
  const flag = (req.query.flag || '').toString().trim().toLowerCase();

  if (search) {
    list = list.filter(v => 
      (v.name && v.name.toLowerCase().includes(search)) ||
      (v.name_en && v.name_en.toLowerCase().includes(search)) ||
      (v.epic && v.epic.toLowerCase().includes(search)) ||
      (v.house_no && v.house_no.toLowerCase().includes(search))
    );
  }
  if (ward && ward !== 'all') {
    list = list.filter(v => String(v.ward_no) === ward);
  }
  if (gender && gender !== 'all') {
    list = list.filter(v => String(v.gender).toLowerCase() === gender);
  }
  if (status && status !== 'all') {
    const isVoted = status === 'VOTED' ? 1 : 0;
    list = list.filter(v => Number(v.is_voted) === isVoted);
  }
  if (flag && flag !== 'all') {
    list = list.filter(v => String(v.flag_color || 'none').toLowerCase() === flag);
  }

  const limit = parseInt(req.query.limit as string) || 0;
  const page = parseInt(req.query.page as string) || 0;
  if (limit > 0 && page > 0) {
    const start = (page - 1) * limit;
    list = list.slice(start, start + limit);
  }

  res.json(list.map(v => {
    const epic = (v.epic || '').trim();
    const photo = v.photo_url || `photos/${epic}.jpg`;
    return {
      ...v,
      id: v.id || v.sl_no,
      sl_no: v.sl_no,
      slNo: v.sl_no,
      name: v.name_te || v.name,
      name_te: v.name_te || v.name,
      name_en: v.name_en,
      nameEn: v.name_en,
      relation_type: v.relation_type,
      relationType: v.relation_type,
      relation_name: v.relation_name || v.guardian_name_te,
      relationName: v.relation_name || v.guardian_name_te,
      relation_name_te: v.relation_name || v.guardian_name_te,
      relation_name_en: v.relation_name_en || v.guardian_name_en,
      relationNameEn: v.relation_name_en || v.guardian_name_en,
      house_no: v.house_no,
      houseNo: v.house_no,
      ward_no: String(v.ward_no),
      wardNo: String(v.ward_no),
      photo_url: photo,
      photoUrl: photo,
      votingStatus: Number(v.is_voted) === 1 ? 'VOTED' : 'NOT_VOTED',
      flagColor: v.flag_color || 'none'
    };
  }));
});

// Vote Toggle
app.post(['/api/voters/vote', '/api/voters/vote/', '/voters_php/api/voters/vote', '/voters_php/api/voters/vote/'], (req: Request, res: Response) => {
  const voterId = req.body?.voterId || req.body?.id;
  const v = cachedVoters.find(x => String(x.id) === String(voterId) || String(x.sl_no) === String(voterId) || x.epic === voterId);
  if (v) {
    v.is_voted = v.is_voted ? 0 : 1;
    res.json({ success: true, is_voted: v.is_voted, votingStatus: v.is_voted ? 'VOTED' : 'NOT_VOTED' });
  } else {
    res.json({ success: true, is_voted: 1, votingStatus: 'VOTED' });
  }
});

// Flag Toggle
app.post(['/api/voters/flag', '/api/voters/flag/', '/voters_php/api/voters/flag', '/voters_php/api/voters/flag/'], (req: Request, res: Response) => {
  const voterId = req.body?.voterId || req.body?.id;
  const flagColor = (req.body?.flagColor || req.body?.flag_color || 'none').toLowerCase();
  const v = cachedVoters.find(x => String(x.id) === String(voterId) || String(x.sl_no) === String(voterId) || x.epic === voterId);
  if (v) {
    v.flag_color = flagColor;
  }
  res.json({ success: true, flagColor, flag_color: flagColor });
});

// Villages Endpoint
app.get(['/api/villages', '/api/villages/', '/voters_php/api/villages', '/voters_php/api/villages/'], (req: Request, res: Response) => {
  res.json([{
    id: '1',
    numeric_id: 1,
    name: 'Kommuvalasa',
    name_te: 'కొమ్మువలస',
    state: 'Andhra Pradesh',
    district: 'Srikakulam',
    constituency: 'Patapatnam',
    mandal: 'L.N. Peta',
    booth_name: 'School',
    part_no: '160',
    fileName: 'voters.pdf',
    isDefault: true,
    voterCount: cachedVoters.length || 806,
    votedCount: cachedVoters.filter(v => Number(v.is_voted) === 1).length
  }]);
});

// Health check
app.get(['/api/health', '/api/health/', '/voters_php/api/health', '/voters_php/api/health/'], (req, res) => {
  res.json({ status: 'ok', cors: 'enabled' });
});

app.listen(Number(PORT), '0.0.0.0', () => {
  console.log(`[Voters Dev Server] Active on port ${PORT}`);
});
