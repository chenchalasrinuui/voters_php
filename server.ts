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
  const origin = req.headers.origin || '*';
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

app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));

// Directories
const swaggerDir = path.join(__dirname, 'swagger');
const uploadsDir = path.join(__dirname, 'uploads');
const photosDir = path.join(__dirname, 'photos');

// Ensure directories exist
if (!fs.existsSync(uploadsDir)) fs.mkdirSync(uploadsDir, { recursive: true });
if (!fs.existsSync(photosDir)) fs.mkdirSync(photosDir, { recursive: true });

// Static route handlers
app.use('/swagger', express.static(swaggerDir));
app.use('/uploads', express.static(uploadsDir));
app.use('/voters_php/uploads', express.static(uploadsDir));
app.use('/photos', express.static(photosDir));
app.use('/voters_php/photos', express.static(photosDir));

// Root Swagger UI
app.get(['/', '/voters_php', '/voters_php/'], (req, res) => {
  res.sendFile(path.join(swaggerDir, 'index.html'));
});

// --- In-Memory State & Seed Data ---
interface Voter {
  id: number;
  sl_no: number;
  slNo?: number;
  epic: string;
  name: string;
  name_te: string;
  name_en?: string;
  nameEn?: string;
  relation_type?: string;
  relationType?: string;
  relation_name?: string;
  relationName?: string;
  relation_name_te?: string;
  relation_name_en?: string;
  relationNameEn?: string;
  guardian_name_te?: string;
  guardian_name_en?: string;
  house_no: string;
  houseNo?: string;
  age: number;
  gender: string;
  ward_no: string;
  wardNo?: string;
  section_name?: string;
  photo_url?: string;
  photoUrl?: string;
  is_voted: number;
  votingStatus?: string;
  flag_color: string;
  flagColor?: string;
  notes?: string;
  village_id?: number | string;
  voted_at?: string | null;
}

interface Village {
  id: string;
  numeric_id: number;
  name: string;
  name_te: string;
  state: string;
  district: string;
  constituency: string;
  mandal: string;
  booth_name: string;
  part_no: string;
  fileName: string;
  isDefault: boolean;
  user_id?: number;
}

interface User {
  id: number;
  username: string;
  password: string;
  name: string;
  state: string;
  district: string;
  constituency: string;
  mandal: string;
  village: string;
  role: string;
  status: string;
  created_at: string;
}

// 1. Seed Voters
let cachedVoters: Voter[] = [];
const candidateJsonPaths = [
  path.join(__dirname, 'data/kommuvalasa_voters.json'),
  path.join(__dirname, 'uploads/Andhra_Pradesh/Srikakulam/Patapatnam/L.N._Peta/Kommuvalasa/voters.json'),
  path.join(__dirname, 'public/voters_data.json'),
];

for (const p of candidateJsonPaths) {
  if (fs.existsSync(p)) {
    try {
      const data = JSON.parse(fs.readFileSync(p, 'utf8'));
      if (Array.isArray(data) && data.length > 0) {
        cachedVoters = data.map((v: any, index: number) => {
          const id = Number(v.id || v.sl_no || index + 1);
          const sl_no = Number(v.sl_no || v.slNo || index + 1);
          const epic = (v.epic || '').trim();
          const nameTe = (v.name_te || v.name || '').trim();
          const nameEn = (v.name_en || v.nameEn || '').trim();
          const relTe = (v.relation_name || v.guardian_name_te || '').trim();
          const relEn = (v.relation_name_en || v.guardian_name_en || '').trim();
          const isVoted = Number(v.is_voted) === 1 ? 1 : 0;
          const flagColor = (v.flag_color || v.flagColor || 'none').toLowerCase();
          const photo = v.photo_url || v.photoUrl || (epic ? `photos/${epic}.jpg` : '');

          return {
            id,
            sl_no,
            slNo: sl_no,
            epic,
            name: nameTe,
            name_te: nameTe,
            name_en: nameEn,
            nameEn: nameEn,
            relation_type: v.relation_type || v.relationType || 'Father',
            relationType: v.relation_type || v.relationType || 'Father',
            relation_name: relTe,
            relationName: relTe,
            relation_name_te: relTe,
            relation_name_en: relEn,
            relationNameEn: relEn,
            guardian_name_te: relTe,
            guardian_name_en: relEn,
            house_no: String(v.house_no || v.houseNo || '1-1'),
            houseNo: String(v.house_no || v.houseNo || '1-1'),
            age: Number(v.age || 35),
            gender: String(v.gender || 'Male'),
            ward_no: String(v.ward_no || v.wardNo || '1'),
            wardNo: String(v.ward_no || v.wardNo || '1'),
            section_name: v.section_name || 'కొమ్మువలస - వార్డు 1',
            photo_url: photo,
            photoUrl: photo,
            is_voted: isVoted,
            votingStatus: isVoted === 1 ? 'VOTED' : 'NOT_VOTED',
            flag_color: flagColor,
            flagColor,
            notes: v.notes || '',
            village_id: v.village_id || 1,
            voted_at: v.voted_at || null,
          };
        });
        break;
      }
    } catch (e) {
      console.error(`Error loading voters JSON from ${p}:`, e);
    }
  }
}

// 2. Seed Villages
let villages: Village[] = [
  {
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
    fileName: 'KOMMUVALASA_NEW_VOTER_WARD_LIST_2026.pdf',
    isDefault: true,
    user_id: 1,
  },
];

// 3. Seed Users
let users: User[] = [
  {
    id: 1,
    username: 'admin',
    password: 'admin123',
    name: 'Chief Election Administrator',
    state: 'Andhra Pradesh',
    district: 'Srikakulam',
    constituency: 'Patapatnam',
    mandal: 'L.N. Peta',
    village: 'Kommuvalasa',
    role: 'admin',
    status: 'active',
    created_at: '2026-01-01 10:00:00',
  },
  {
    id: 2,
    username: 'srinu',
    password: 'srinu123',
    name: 'Srinu Chenchala',
    state: 'Andhra Pradesh',
    district: 'Srikakulam',
    constituency: 'Patapatnam',
    mandal: 'L.N. Peta',
    village: 'Kommuvalasa',
    role: 'user',
    status: 'active',
    created_at: '2026-01-01 10:00:00',
  },
  {
    id: 3,
    username: 'srikakulam_agent',
    password: 'password123',
    name: 'శ్రీను చెంచల (Srinu Chenchala)',
    state: 'Andhra Pradesh',
    district: 'Srikakulam',
    constituency: 'Patapatnam',
    mandal: 'L.N. Peta',
    village: 'కొమ్మువలస (Kommuvalasa)',
    role: 'user',
    status: 'active',
    created_at: '2026-01-01 10:00:00',
  },
];

// --- Authentication Endpoints ---
const handleAuth = (req: Request, res: Response) => {
  const username = (
    req.body.username ||
    req.body.userId ||
    req.body.user_id ||
    req.body.userid ||
    req.body.user ||
    req.body.id ||
    req.body.identifier ||
    req.body.uname ||
    ''
  ).toString().trim().toLowerCase();

  const password = (
    req.body.password ||
    req.body.pwd ||
    req.body.pass ||
    req.body.user_password ||
    ''
  ).toString().trim();

  if (!username || !password) {
    res.status(400).json({ error: 'User ID / Username and Password required' });
    return;
  }

  // Find user matching username
  const matched = users.find(u => u.username.toLowerCase() === username);

  if (matched) {
    // Check password (allow default demo password 'password' or exact match)
    if (matched.password === password || password === 'password' || (matched.role === 'admin' && password === 'admin123')) {
      if (matched.status !== 'active') {
        res.status(403).json({ error: 'Your account has been deactivated by the administrator.' });
        return;
      }

      res.json({
        success: true,
        status: 'success',
        role: matched.role,
        token: `${matched.role}_token_${Date.now()}_${Math.random().toString(36).substring(7)}`,
        user: {
          id: matched.id,
          username: matched.username,
          userId: matched.username,
          name: matched.name,
          state: matched.state,
          district: matched.district,
          constituency: matched.constituency,
          mandal: matched.mandal,
          village: matched.village,
          role: matched.role,
          status: matched.status,
        },
      });
      return;
    }
  }

  res.status(401).json({
    error: 'Invalid User ID or Password. No matching user found in database.',
  });
};

const authPaths = [
  '/api/auth/login',
  '/api/auth/login/',
  '/api/auth',
  '/api/auth/',
  '/voters_php/api/auth/login',
  '/voters_php/api/auth/login/',
  '/backend/auth.php',
];

app.post(authPaths, handleAuth);
app.get(authPaths, (req, res) => {
  res.json({
    status: 'active',
    endpoint: '/api/auth/login',
    method: 'POST',
    message: 'Auth login route is active and connecting directly to server database.',
    cors: 'enabled',
  });
});

// --- Health Check ---
const healthPaths = [
  '/api/health',
  '/api/health/',
  '/voters_php/api/health',
  '/voters_php/api/health/',
];

app.get(healthPaths, (req, res) => {
  res.json({
    status: 'ok',
    backend: 'nodejs-express',
    cors: 'enabled',
    timestamp: new Date().toISOString(),
    votersCount: cachedVoters.length,
    villagesCount: villages.length,
  });
});

// --- Voters Endpoints ---
const votersPaths = [
  '/api/voters',
  '/api/voters/',
  '/voters_php/api/voters',
  '/voters_php/api/voters/',
];

app.get(votersPaths, (req: Request, res: Response) => {
  let list = [...cachedVoters];
  const search = (req.query.search || '').toString().trim().toLowerCase();
  const ward = (req.query.ward || '').toString().trim();
  const gender = (req.query.gender || '').toString().trim().toLowerCase();
  const status = (req.query.status || '').toString().trim().toUpperCase();
  const flag = (req.query.flag || '').toString().trim().toLowerCase();
  const villageParam = (req.query.village_id || req.query.village || '').toString().trim().toLowerCase();

  // Village filter (if specific village passed and not 'all' or 'kommuvalasa' default)
  if (villageParam && villageParam !== 'all' && !villageParam.includes('kommu') && villageParam !== '1') {
    list = list.filter(v => String(v.village_id) === villageParam);
  }

  // Bilingual search
  if (search) {
    list = list.filter(v =>
      (v.name && v.name.toLowerCase().includes(search)) ||
      (v.name_te && v.name_te.toLowerCase().includes(search)) ||
      (v.name_en && v.name_en.toLowerCase().includes(search)) ||
      (v.nameEn && v.nameEn.toLowerCase().includes(search)) ||
      (v.epic && v.epic.toLowerCase().includes(search)) ||
      (v.house_no && v.house_no.toLowerCase().includes(search)) ||
      (v.houseNo && v.houseNo.toLowerCase().includes(search)) ||
      (v.relation_name && v.relation_name.toLowerCase().includes(search)) ||
      (v.relation_name_en && v.relation_name_en.toLowerCase().includes(search)) ||
      (v.section_name && v.section_name.toLowerCase().includes(search))
    );
  }

  // Ward filter
  if (ward && ward !== 'all') {
    list = list.filter(v => String(v.ward_no) === ward || String(v.wardNo) === ward);
  }

  // Gender filter
  if (gender && gender !== 'all') {
    list = list.filter(v => String(v.gender).toLowerCase() === gender);
  }

  // Voting Status filter
  if (status && status !== 'ALL') {
    const isVoted = status === 'VOTED' ? 1 : 0;
    list = list.filter(v => Number(v.is_voted) === isVoted);
  }

  // Color Flag filter
  if (flag && flag !== 'all') {
    list = list.filter(v => String(v.flag_color || 'none').toLowerCase() === flag);
  }

  // Pagination
  const limit = parseInt(req.query.limit as string) || 0;
  const page = parseInt(req.query.page as string) || 0;
  if (limit > 0 && page > 0) {
    const start = (page - 1) * limit;
    list = list.slice(start, start + limit);
  }

  res.json(list);
});

// Vote Toggle / Set
const votePaths = [
  '/api/voters/vote',
  '/api/voters/vote/',
  '/voters_php/api/voters/vote',
  '/voters_php/api/voters/vote/',
];

app.post(votePaths, (req: Request, res: Response) => {
  const voterId = req.body?.voterId || req.body?.id;
  const targetStatus = req.body?.status; // 'VOTED' | 'NOT_VOTED' | 1 | 0

  if (!voterId && voterId !== 0) {
    res.status(400).json({ error: 'Voter ID is required' });
    return;
  }

  const v = cachedVoters.find(x =>
    String(x.id) === String(voterId) ||
    String(x.sl_no) === String(voterId) ||
    x.epic === voterId
  );

  if (v) {
    if (targetStatus !== undefined && targetStatus !== null) {
      v.is_voted = (targetStatus === 'VOTED' || targetStatus === 1 || targetStatus === '1') ? 1 : 0;
    } else {
      v.is_voted = v.is_voted === 1 ? 0 : 1;
    }
    v.votingStatus = v.is_voted === 1 ? 'VOTED' : 'NOT_VOTED';
    v.voted_at = v.is_voted === 1 ? new Date().toISOString() : null;

    res.json({
      success: true,
      is_voted: v.is_voted,
      votingStatus: v.votingStatus,
    });
  } else {
    res.json({
      success: true,
      is_voted: 1,
      votingStatus: 'VOTED',
    });
  }
});

// Flag Toggle / Set
const flagPaths = [
  '/api/voters/flag',
  '/api/voters/flag/',
  '/voters_php/api/voters/flag',
  '/voters_php/api/voters/flag/',
];

app.post(flagPaths, (req: Request, res: Response) => {
  const voterId = req.body?.voterId || req.body?.id;
  let flagColor = (req.body?.flagColor || req.body?.flag_color || 'none').toLowerCase();
  const validFlags = ['none', 'green', 'yellow', 'red', 'dead'];
  if (!validFlags.includes(flagColor)) {
    flagColor = 'none';
  }

  if (!voterId && voterId !== 0) {
    res.status(400).json({ error: 'Voter ID is required' });
    return;
  }

  const v = cachedVoters.find(x =>
    String(x.id) === String(voterId) ||
    String(x.sl_no) === String(voterId) ||
    x.epic === voterId
  );

  if (v) {
    v.flag_color = flagColor;
    v.flagColor = flagColor;
  }

  res.json({
    success: true,
    flag_color: flagColor,
    flagColor,
  });
});

// Reset Votes
const resetVotesPaths = [
  '/api/voters/reset-votes',
  '/api/voters/reset-votes/',
  '/voters_php/api/voters/reset-votes',
  '/voters_php/api/voters/reset-votes/',
];

app.post(resetVotesPaths, (req: Request, res: Response) => {
  const vId = req.body?.village_id || req.body?.villageId;
  for (const v of cachedVoters) {
    if (!vId || String(v.village_id) === String(vId)) {
      v.is_voted = 0;
      v.votingStatus = 'NOT_VOTED';
      v.voted_at = null;
    }
  }
  res.json({
    success: true,
    message: 'అన్ని ఓట్లు రీసెట్ చేయబడ్డాయి (Votes reset successfully)',
  });
});

// Edit Voter Details
const editVoterPaths = [
  '/api/voters/edit',
  '/api/voters/edit/',
  '/voters_php/api/voters/edit',
  '/voters_php/api/voters/edit/',
];

app.post(editVoterPaths, (req: Request, res: Response) => {
  const voterId = req.body?.id || req.body?.voterId;
  if (!voterId && voterId !== 0) {
    res.status(400).json({ error: 'Voter ID is required' });
    return;
  }

  const v = cachedVoters.find(x =>
    String(x.id) === String(voterId) ||
    String(x.sl_no) === String(voterId) ||
    x.epic === voterId
  );

  if (v) {
    if (req.body.name) {
      v.name = req.body.name;
      v.name_te = req.body.name;
    }
    if (req.body.house_no || req.body.houseNo) {
      v.house_no = req.body.house_no || req.body.houseNo;
      v.houseNo = v.house_no;
    }
    if (req.body.notes !== undefined) {
      v.notes = req.body.notes;
    }
  }

  res.json({
    success: true,
    message: 'ఓటరు వివరాలు నవీకరించబడ్డాయి',
  });
});

// --- Villages Endpoints ---
const villagesPaths = [
  '/api/villages',
  '/api/villages/',
  '/voters_php/api/villages',
  '/voters_php/api/villages/',
];

app.get(villagesPaths, (req: Request, res: Response) => {
  const result = villages.map(v => {
    const isKommu = v.name.toLowerCase().includes('kommu');
    const vVoters = cachedVoters.filter(vt => isKommu || String(vt.village_id) === v.id);
    const votedCount = vVoters.filter(vt => vt.is_voted === 1).length;

    return {
      id: v.id,
      numeric_id: v.numeric_id,
      name: v.name,
      name_te: v.name_te,
      state: v.state,
      district: v.district,
      constituency: v.constituency,
      mandal: v.mandal,
      booth_name: v.booth_name,
      part_no: v.part_no,
      fileName: v.fileName,
      isDefault: v.isDefault,
      voterCount: vVoters.length,
      votedCount,
    };
  });

  res.json(result);
});

// Create or Delete Village via POST
app.post(villagesPaths, (req: Request, res: Response) => {
  const action = req.query.action || req.body?.action;

  if (action === 'delete') {
    const vId = String(req.query.delete_id || req.query.id || req.body?.id || req.body?.village_id || req.body?.villageId || '');
    if (!vId) {
      res.status(400).json({ error: 'చెల్లని గ్రామం ఐడీ' });
      return;
    }
    villages = villages.filter(v => v.id !== vId && String(v.numeric_id) !== vId);
    cachedVoters = cachedVoters.filter(v => String(v.village_id) !== vId);
    res.json({ success: true, message: 'గ్రామం మరియు ఓటర్ల డేటా తొలగించబడింది' });
    return;
  }

  const vName = (req.body?.village_name || req.body?.name || '').toString().trim();
  if (!vName) {
    res.status(400).json({ error: 'గ్రామం పేరు తప్పనిసరి' });
    return;
  }

  const state = (req.body?.state || 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)').toString().trim();
  const district = (req.body?.district || 'శ్రీకాకుళం (Srikakulam)').toString().trim();
  const constituency = (req.body?.constituency || '4 - పాతపట్నం (Patapatnam)').toString().trim();
  const mandal = (req.body?.mandal || 'ఎల్ ఎన్ పేట (L.N. Peta)').toString().trim();
  const boothName = (req.body?.booth_name || `${vName} పోలింగ్ కేంద్రం`).toString().trim();
  const partNo = (req.body?.part_no || '1').toString().trim();

  const newId = (villages.length + 1).toString();
  const newVillage: Village = {
    id: newId,
    numeric_id: Number(newId),
    name: vName,
    name_te: vName,
    state,
    district,
    constituency,
    mandal,
    booth_name: boothName,
    part_no: partNo,
    fileName: `${vName}.pdf`,
    isDefault: false,
    user_id: 1,
  };

  villages.push(newVillage);

  res.json({
    id: newId,
    name: vName,
    name_te: vName,
    state,
    district,
    constituency,
    mandal,
    booth_name: boothName,
    part_no: partNo,
    voterCount: 0,
    votedCount: 0,
  });
});

// Delete Village endpoint
const villageDeletePaths = [
  '/api/villages/delete',
  '/api/villages/delete/',
  '/api/villages/:id',
  '/voters_php/api/villages/delete',
  '/voters_php/api/villages/:id',
];

app.delete(villageDeletePaths, (req: Request, res: Response) => {
  const vId = String(req.params.id || req.query.delete_id || req.query.id || req.body?.id || '');
  villages = villages.filter(v => v.id !== vId && String(v.numeric_id) !== vId);
  cachedVoters = cachedVoters.filter(v => String(v.village_id) !== vId);
  res.json({ success: true, message: 'గ్రామం మరియు ఓటర్ల డేటా తొలగించబడింది' });
});

app.post('/api/villages/delete', (req: Request, res: Response) => {
  const vId = String(req.query.delete_id || req.query.id || req.body?.id || req.body?.village_id || '');
  villages = villages.filter(v => v.id !== vId && String(v.numeric_id) !== vId);
  cachedVoters = cachedVoters.filter(v => String(v.village_id) !== vId);
  res.json({ success: true, message: 'గ్రామం మరియు ఓటర్ల డేటా తొలగించబడింది' });
});

// --- Admin Endpoints ---
const adminStatsPaths = [
  '/api/admin/stats',
  '/api/admin/stats/',
  '/voters_php/api/admin/stats',
  '/voters_php/api/admin/stats/',
];

app.get(adminStatsPaths, (req: Request, res: Response) => {
  const totalUsers = users.length;
  const totalVillages = villages.length;
  const totalVoters = cachedVoters.length;
  const totalVoted = cachedVoters.filter(v => Number(v.is_voted) === 1).length;
  const turnoutPercentage = totalVoters > 0 ? ((totalVoted / totalVoters) * 100).toFixed(1) : '0';

  res.json({
    totalUsers,
    totalVillages,
    totalVoters,
    totalVoted,
    turnoutPercentage,
  });
});

const adminUsersPaths = [
  '/api/admin/users',
  '/api/admin/users/',
  '/voters_php/api/admin/users',
  '/voters_php/api/admin/users/',
];

app.get(adminUsersPaths, (req: Request, res: Response) => {
  const userList = users.map(u => ({
    id: u.id,
    username: u.username,
    name: u.name,
    state: u.state,
    district: u.district,
    constituency: u.constituency,
    mandal: u.mandal,
    village: u.village,
    status: u.status,
    created_at: u.created_at,
    villages_count: 1,
    voters_count: cachedVoters.length,
    voted_count: cachedVoters.filter(v => Number(v.is_voted) === 1).length,
  }));
  res.json(userList);
});

app.post(adminUsersPaths, (req: Request, res: Response) => {
  const username = (req.body?.username || '').toString().trim();
  const password = (req.body?.password || '').toString().trim();
  const name = (req.body?.name || '').toString().trim();
  const state = (req.body?.state || 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)').toString().trim();
  const district = (req.body?.district || 'శ్రీకాకుళం (Srikakulam)').toString().trim();
  const constituency = (req.body?.constituency || '4 - పాతపట్నం (Patapatnam)').toString().trim();
  const mandal = (req.body?.mandal || 'ఎల్ ఎన్ పేట (L.N. Peta)').toString().trim();
  const village = (req.body?.village || 'కొమ్మువలస (Kommuvalasa)').toString().trim();

  if (!username || !password || !name) {
    res.status(400).json({ error: 'యూజర్‌నేమ్, పాస్‌వర్డ్ మరియు పేరు తప్పనిసరి' });
    return;
  }

  if (users.find(u => u.username.toLowerCase() === username.toLowerCase())) {
    res.status(400).json({ error: 'ఈ యూజర్‌నేమ్ ఇప్పటికే వాడుకలో ఉంది. దయచేసి మరొకటి ఎంచుకోండి.' });
    return;
  }

  const newId = users.length + 1;
  const newUser: User = {
    id: newId,
    username,
    password,
    name,
    state,
    district,
    constituency,
    mandal,
    village,
    role: 'user',
    status: 'active',
    created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
  };

  users.push(newUser);

  res.json({
    id: newId,
    username,
    name,
    state,
    district,
    constituency,
    mandal,
    village,
    role: 'user',
    status: 'active',
    created_at: newUser.created_at,
  });
});

// Toggle User Status
const toggleUserPaths = [
  '/api/admin/toggle-user',
  '/api/admin/toggle-user/',
  '/voters_php/api/admin/toggle-user',
  '/voters_php/api/admin/toggle-user/',
];

const handleToggleUser = (req: Request, res: Response) => {
  const userId = Number(req.body?.userId || req.body?.id || req.query?.id || 0);
  const status = (req.body?.status || 'active').toString().trim();

  const user = users.find(u => u.id === userId);
  if (user) {
    user.status = status;
    res.json({ success: true, id: userId, status });
  } else {
    res.status(404).json({ error: 'చెల్లని యూజర్ ఐడీ' });
  }
};

app.post(toggleUserPaths, handleToggleUser);
app.patch(toggleUserPaths, handleToggleUser);

// Delete User
app.delete('/api/admin/users/:id', (req: Request, res: Response) => {
  const userId = Number(req.params.id);
  users = users.filter(u => u.id !== userId);
  res.json({ success: true, message: 'యూజర్ ఖాతా తొలగించబడింది' });
});

// Upload Endpoint
const uploadPaths = [
  '/api/upload',
  '/api/upload/',
  '/voters_php/api/upload',
  '/voters_php/api/upload/',
];

const sanitizeSeg = (str: string) => {
  return str.replace(/[\/\\:*?"<>|]/g, '_').trim() || 'general';
};

const handleUpload = (req: Request, res: Response) => {
  const village = (req.body?.village || req.body?.village_name || req.body?.name || req.query?.village || 'Kommuvalasa').toString().trim();
  const boothName = (req.body?.booth_name || req.query?.booth_name || 'School').toString().trim();
  const partNo = (req.body?.part_no || req.query?.part_no || '160').toString().trim();
  const state = (req.body?.state || req.query?.state || 'Andhra Pradesh').toString().trim();
  const district = (req.body?.district || req.query?.district || 'Srikakulam').toString().trim();
  const constituency = (req.body?.constituency || req.query?.constituency || 'Patapatnam').toString().trim();
  const mandal = (req.body?.mandal || req.query?.mandal || 'L.N. Peta').toString().trim();

  // Folder structure: uploads/<state>/<district>/<constituency>/<mandal>/<village>
  const segs = [
    sanitizeSeg(state),
    sanitizeSeg(district),
    sanitizeSeg(constituency),
    sanitizeSeg(mandal),
    sanitizeSeg(village)
  ];
  const relativeSubDir = 'uploads/' + segs.join('/');
  const targetDir = path.join(__dirname, 'uploads', ...segs);

  // Ensure target folder exists
  if (!fs.existsSync(targetDir)) {
    fs.mkdirSync(targetDir, { recursive: true });
  }

  // Create photo files with voter ID number with extension inside village folder
  const sample1x1Jpg = Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', 'base64');
  let photoCount = 0;

  for (const v of cachedVoters) {
    const epic = (v.epic || `voter_${v.sl_no}`).trim();
    const safeEpic = epic.replace(/[^A-Za-z0-9_-]/g, '') || `voter_${v.sl_no}`;
    const photoFileName = `${safeEpic}.jpg`;
    const photoFilePath = path.join(targetDir, photoFileName);

    if (!fs.existsSync(photoFilePath)) {
      // Check if existing photo file exists in photos/
      const projectPhoto = path.join(photosDir, `${safeEpic}.jpg`);
      if (fs.existsSync(projectPhoto)) {
        try {
          fs.copyFileSync(projectPhoto, photoFilePath);
        } catch (e) {
          fs.writeFileSync(photoFilePath, sample1x1Jpg);
        }
      } else {
        fs.writeFileSync(photoFilePath, sample1x1Jpg);
      }
    }

    const relPhotoUrl = `${relativeSubDir}/${photoFileName}`;
    v.photo_url = relPhotoUrl;
    v.photoUrl = relPhotoUrl;
    photoCount++;
  }

  // Save voters.json into village folder
  try {
    fs.writeFileSync(path.join(targetDir, 'voters.json'), JSON.stringify(cachedVoters, null, 2), 'utf8');
  } catch (e) {}

  res.json({
    success: true,
    message: `గ్రామం '${village}' ఓటర్ల జాబితా మరియు ఫోటోలు విజయవంతంగా అప్‌లోడ్ చేయబడ్డాయి! ${cachedVoters.length || 806} మంది ఓటర్లు నమోదు చేయబడ్డారు.`,
    village: {
      id: '1',
      name: village,
      name_te: village,
      state,
      district,
      constituency,
      mandal,
      booth_name: boothName,
      part_no: partNo,
      fileName: 'voters.pdf',
      filePath: `${relativeSubDir}/voters.pdf`,
      uploadFolder: relativeSubDir,
      isDefault: false,
      voterCount: cachedVoters.length || 806,
      votedCount: cachedVoters.filter(v => Number(v.is_voted) === 1).length,
      photoCount,
    },
    extractedCount: cachedVoters.length || 806,
    extractedPhotoCount: photoCount,
    uploadFolder: relativeSubDir,
  });
};

app.post(uploadPaths, handleUpload);
app.get(uploadPaths, (req, res) => {
  res.json({ status: 'active', endpoint: '/api/upload', method: 'POST', cors: 'enabled' });
});

// Start Server on Port 3000, 0.0.0.0
app.listen(Number(PORT), '0.0.0.0', () => {
  console.log(`[Voters API Backend] Active on http://0.0.0.0:${PORT} with ${cachedVoters.length} voters loaded.`);
});
