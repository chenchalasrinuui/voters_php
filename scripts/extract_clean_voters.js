import fs from 'fs';
import path from 'path';
import { PDFParse } from 'pdf-parse';

// Comprehensive Telugu names, surnames, and word dictionary
const dictionary = [
  // Chenchala family
  ['చె ంచె ల', 'చెంచెల', 'Chenchala'],
  ['చె ంచల', 'చెంచల', 'Chenchala'],
  ['చె ంచాల', 'చెంచాల', 'Chenchala'],
  ['చెంచెల', 'చెంచెల', 'Chenchala'],
  ['చెంచల', 'చెంచల', 'Chenchala'],
  ['చెంచాల', 'చెంచాల', 'Chenchala'],
  ['శీరా ములు', 'శ్రీరాములు', 'Sreeramulu'],
  ['శీరాములు', 'శ్రీరాములు', 'Sreeramulu'],
  ['శ్రీరాములు', 'శ్రీరాములు', 'Sreeramulu'],
  ['శీనివా సరా వు', 'శ్రీనివాసరావు', 'Srinivasa Rao'],
  ['శీనివాసరావు', 'శ్రీనివాసరావు', 'Srinivasa Rao'],
  ['శ్రీనివాసరావు', 'శ్రీనివాసరావు', 'Srinivasa Rao'],
  ['రా జులమ', 'రాజులమ్మ', 'Rajulamma'],
  ['రాజులమ్మ', 'రాజులమ్మ', 'Rajulamma'],
  ['పా వని', 'పావని', 'Pavani'],
  ['పావని', 'పావని', 'Pavani'],
  ['లచ న', 'లచ్చన్న', 'Lachhanna'],
  ['లచ్చన్న', 'లచ్చన్న', 'Lachhanna'],
  
  // Kornu family
  ['చి మ కొ రు', 'చిట్టెమ్మ కొర్ను', 'Chittemma Kornu'],
  ['చి మ', 'చిట్టెమ్మ', 'Chittemma'],
  ['చిట్టెమ్మ', 'చిట్టెమ్మ', 'Chittemma'],
  ['జజనా ర నరా వు కొ రు', 'జనార్దనరావు కొర్ను', 'Janardhana Rao Kornu'],
  ['జజనా ర నరా వు', 'జనార్దనరావు', 'Janardhana Rao'],
  ['జనా ర నరా వు', 'జనార్దనరావు', 'Janardhana Rao'],
  ['జనార్దనరావు', 'జనార్దనరావు', 'Janardhana Rao'],
  ['జా నకమ కొ రు', 'జానకమ్మ కొర్ను', 'Janakamma Kornu'],
  ['జా నకమ', 'జానకమ్మ', 'Janakamma'],
  ['జానకమ్మ', 'జానకమ్మ', 'Janakamma'],
  ['కొ రు', 'కొర్ను', 'Kornu'],
  ['కొర్ను', 'కొర్ను', 'Kornu'],
  ['కోర్ను', 'కొర్ను', 'Kornu'],
  ['యరయ', 'యర్రయ్య', 'Yerrayya'],
  ['యె రయ', 'యర్రయ్య', 'Yerrayya'],
  ['యెర్రయ్య', 'యర్రయ్య', 'Yerrayya'],
  ['యర్రయ్య', 'యర్రయ్య', 'Yerrayya'],
  
  // Yalla family
  ['పో లమ యా ళ', 'పోలమ్మ యాళ్ల', 'Polamma Yalla'],
  ['పో లమ', 'పోలమ్మ', 'Polamma'],
  ['పోలమ్మ', 'పోలమ్మ', 'Polamma'],
  ['యాలా మురళీ', 'మురళీ యాళ్ల', 'Murali Yalla'],
  ['మురళీ', 'మురళీ', 'Murali'],
  ['మురళి', 'మురళి', 'Murali'],
  ['యల రేవతి', 'రేవతి యాళ్ల', 'Revathi Yalla'],
  ['రేవతి', 'రేవతి', 'Revathi'],
  ['రామారా వు యా ళ', 'రామారావు యాళ్ల', 'Rama Rao Yalla'],
  ['రామారా వు', 'రామారావు', 'Rama Rao'],
  ['రామారావు', 'రామారావు', 'Rama Rao'],
  ['కుమారి యా ల', 'కుమారి యాళ్ల', 'Kumari Yalla'],
  ['కుమారి', 'కుమారి', 'Kumari'],
  ['ఆదినారాయణ', 'ఆదినారాయణ', 'Adinarayana'],
  ['ఆదీనారాయణ', 'ఆదినారాయణ', 'Adinarayana'],
  ['యా ళ', 'యాళ్ల', 'Yalla'],
  ['యా ల', 'యాళ్ల', 'Yalla'],
  ['యల', 'యాళ్ల', 'Yalla'],
  ['యాళ్ల', 'యాళ్ల', 'Yalla'],
  ['యాల', 'యాళ్ల', 'Yalla'],
  
  // Reddi family
  ['సంజీవరా వు రెడి', 'సంజీవరావు రెడ్డి', 'Sanjeeva Rao Reddi'],
  ['సంజీవరా వు', 'సంజీవరావు', 'Sanjeeva Rao'],
  ['సంజీవరావు', 'సంజీవరావు', 'Sanjeeva Rao'],
  ['లక్షి రెడి', 'లక్ష్మి రెడ్డి', 'Lakshmi Reddi'],
  ['లక్షి', 'లక్ష్మి', 'Lakshmi'],
  ['లక్ష్మి', 'లక్ష్మి', 'Lakshmi'],
  ['సుధీర్ రెడి', 'సుధీర్ రెడ్డి', 'Sudheer Reddi'],
  ['సుధీర్', 'సుధీర్', 'Sudheer'],
  ['సూర నారా యణ రెడి', 'సూర్యనారాయణ రెడ్డి', 'Suryanarayana Reddi'],
  ['సూర నారా యణ', 'సూర్యనారాయణ', 'Suryanarayana'],
  ['సూర్యనారాయణ', 'సూర్యనారాయణ', 'Suryanarayana'],
  ['నీ లవేణి రెడి', 'నీలవేణి రెడ్డి', 'Neelaveni Reddi'],
  ['నీ లవేణి', 'నీలవేణి', 'Neelaveni'],
  ['నీలవేణి', 'నీలవేణి', 'Neelaveni'],
  ['రెడి చలపతిరా వు', 'చలపతిరావు రెడ్డి', 'Chalapathi Rao Reddi'],
  ['చలపతిరా వు', 'చలపతిరావు', 'Chalapathi Rao'],
  ['చలపతిరావు', 'చలపతిరావు', 'Chalapathi Rao'],
  ['సూరయ రెడి', 'సూరయ్య రెడ్డి', 'Soorayya Reddi'],
  ['సూరయ', 'సూరయ్య', 'Soorayya'],
  ['సూరయ్య', 'సూరయ్య', 'Soorayya'],
  ['రామారా వు రెడి', 'రామారావు రెడ్డి', 'Rama Rao Reddi'],
  ['వి జయలక్షి రెడి', 'విజయలక్ష్మి రెడ్డి', 'Vijayalakshmi Reddi'],
  ['వి జయలక్షి', 'విజయలక్ష్మి', 'Vijayalakshmi'],
  ['విజయలక్ష్మి', 'విజయలక్ష్మి', 'Vijayalakshmi'],
  ['నారా యణ రా వు రెడి', 'నారాయణరావు రెడ్డి', 'Narayana Rao Reddi'],
  ['రా మ రా వు రెడి', 'రామారావు రెడ్డి', 'Rama Rao Reddi'],
  ['రెడి', 'రెడ్డి', 'Reddi'],
  ['రెడ్డి', 'రెడ్డి', 'Reddi'],
  ['దశాలు', 'దశాలు', 'Dasalu'],
  
  // Sambana family
  ['గోవి ందరా వు శంబా న', 'గోవిందరావు శాంబన', 'Govinda Rao Sambana'],
  ['గోవి ందరా వు', 'గోవిందరావు', 'Govinda Rao'],
  ['గోవిందరావు', 'గోవిందరావు', 'Govinda Rao'],
  ['సుశీ ల శంబా న', 'సుశీల శాంబన', 'Suseela Sambana'],
  ['సుశీ ల', 'సుశీల', 'Suseela'],
  ['సుశీల', 'సుశీల', 'Suseela'],
  ['శంబన సంతో మార్', 'సంతోష్ కుమార్ శాంబన', 'Santosh Kumar Sambana'],
  ['సంతో మార్', 'సంతోష్ కుమార్', 'Santosh Kumar'],
  ['సంతోష్ కుమార్', 'సంతోష్ కుమార్', 'Santosh Kumar'],
  ['జా నే శ రి సా ంబన', 'జ్ఞానేశ్వరి శాంబన', 'Gnaneswari Sambana'],
  ['జా నే శ రి', 'జ్ఞానేశ్వరి', 'Gnaneswari'],
  ['జ్ఞానేశ్వరి', 'జ్ఞానేశ్వరి', 'Gnaneswari'],
  ['నరేశ్ సా ంబన', 'నరేశ్ శాంబన', 'Naresh Sambana'],
  ['నరేశ్', 'నరేశ్', 'Naresh'],
  ['అనురా ధ శంబా న', 'అనురాధ శాంబన', 'Anuradha Sambana'],
  ['అనురా ధ', 'అనురాధ', 'Anuradha'],
  ['అనురాధ', 'అనురాధ', 'Anuradha'],
  ['తౌ డు శంబా న', 'తౌడు శాంబన', 'Thowdu Sambana'],
  ['తౌ డు', 'తౌడు', 'Thowdu'],
  ['తౌడు', 'తౌడు', 'Thowdu'],
  ['శంభులింగం', 'శంభులింగం', 'Sambhulingam'],
  ['సరోజినీ సంబానా', 'సరోజినీ శాంబన', 'Sarojini Sambana'],
  ['సరోజినీ', 'సరోజినీ', 'Sarojini'],
  ['శంబా న రమేష్', 'రమేష్ శాంబన', 'Ramesh Sambana'],
  ['రమేష్', 'రమేష్', 'Ramesh'],
  ['రమేశ', 'రమేష్', 'Ramesh'],
  ['శంబా న జో తి', 'జ్యోతి శాంబన', 'Jyothi Sambana'],
  ['జో తి', 'జ్యోతి', 'Jyothi'],
  ['జ్యోతి', 'జ్యోతి', 'Jyothi'],
  ['తవుడు', 'తవుడు', 'Thavudu'],
  ['శంబా న', 'శాంబన', 'Sambana'],
  ['సా ంబన', 'శాంబన', 'Sambana'],
  ['శంబన', 'శాంబన', 'Sambana'],
  ['సంబానా', 'శాంబన', 'Sambana'],
  ['శాంబన', 'శాంబన', 'Sambana'],

  // Mookalla family
  ['పవీణ్ కుమార్ మూకళ', 'ప్రవీణ్ కుమార్ మూకళ్ల', 'Praveen Kumar Mookalla'],
  ['పవీణ్ కుమార్', 'ప్రవీణ్ కుమార్', 'Praveen Kumar'],
  ['ప్రవీణ్ కుమార్', 'ప్రవీణ్ కుమార్', 'Praveen Kumar'],
  ['సి ంహాచలం మూకళ', 'సింహాచలం మూకళ్ల', 'Simhachalam Mookalla'],
  ['సి ంహాచలం', 'సింహాచలం', 'Simhachalam'],
  ['సింహాచలం', 'సింహాచలం', 'Simhachalam'],
  ['చి న మ డు మూకళ', 'చిన్నమ్మడు మూకళ్ల', 'Chinnammadu Mookalla'],
  ['చి న మ డు', 'చిన్నమ్మడు', 'Chinnammadu'],
  ['చిన్నమ్మడు', 'చిన్నమ్మడు', 'Chinnammadu'],
  ['పుష పవతి మూకళ', 'పుష్పవతి మూకళ్ల', 'Pushpavathi Mookalla'],
  ['పుష్పవతి', 'పుష్పవతి', 'Pushpavathi'],
  ['తిరుమల రా వు మూకళ', 'తిరుమలరావు మూకళ్ల', 'Thirumala Rao Mookalla'],
  ['తిరుమల రా వు', 'తిరుమలరావు', 'Thirumala Rao'],
  ['తిరుమలరావు', 'తిరుమలరావు', 'Thirumala Rao'],
  [' దా క్ష వేణి మూకళ', 'ద్రాక్షవేణి మూకళ్ల', 'Drakshaveni Mookalla'],
  ['ద్రాక్షవేణి', 'ద్రాక్షవేణి', 'Drakshaveni'],
  ['మూకళ', 'మూకళ్ల', 'Mookalla'],
  ['మూకళ్ల', 'మూకళ్ల', 'Mookalla'],
  ['సి మ న', 'సిమ్మన్న', 'Simmanna'],
  
  // Kuramana family
  ['కి ృ ష వేణి కురమా న', 'కృష్ణవేణి కురుమాన', 'Krishnaveni Kuramana'],
  ['కి ృ ష వేణి', 'కృష్ణవేణి', 'Krishnaveni'],
  ['కృష్ణవేణి', 'కృష్ణవేణి', 'Krishnaveni'],
  ['రా ములమ కురమా న', 'రాములమ్మ కురుమాన', 'Ramulamma Kuramana'],
  ['రా ములమ', 'రాములమ్మ', 'Ramulamma'],
  ['రాములమ్మ', 'రాములమ్మ', 'Ramulamma'],
  ['వె ంకటరమణమూరి కురమా న', 'వెంకటరమణమూర్తి కురుమాన', 'Venkataramana Murthy Kuramana'],
  ['వె ంకటరమణమూరి', 'వెంకటరమణమూర్తి', 'Venkataramana Murthy'],
  ['చి నవె ంకటి కురమా న', 'చిన్నవెంకటి కురుమాన', 'Chinnavenkati Kuramana'],
  ['చి నవె ంకటి', 'చిన్నవెంకటి', 'Chinnavenkati'],
  [' పసాద్ కురమా న', 'ప్రసాద్ కురుమాన', 'Prasad Kuramana'],
  [' పసాద్', 'ప్రసాద్', 'Prasad'],
  ['ప్రసాద్', 'ప్రసాద్', 'Prasad'],
  ['లలిత కుమారి కురమా న', 'లలితకుమారి కురుమాన', 'Lalitha Kumari Kuramana'],
  ['లలిత కుమారి', 'లలితకుమారి', 'Lalitha Kumari'],
  ['బో డయ కురమా న', 'బోడయ్య కురుమాన', 'Bodayya Kuramana'],
  ['బో డయ', 'బోడయ్య', 'Bodayya'],
  ['బోడయ్య', 'బోడయ్య', 'Bodayya'],
  ['తవిటమ కురమా న', 'తవిటమ్మ కురుమాన', 'Thavitamma Kuramana'],
  ['తవిటమ', 'తవిటమ్మ', 'Thavitamma'],
  ['తవిటమ్మ', 'తవిటమ్మ', 'Thavitamma'],
  ['రా ము కురమా న', 'రాము కురుమాన', 'Ramu Kuramana'],
  ['రా ము', 'రాము', 'Ramu'],
  ['కురమా న', 'కురుమాన', 'Kuramana'],
  ['కురుమాన', 'కురుమాన', 'Kuramana'],
  ['బంగారయ', 'బంగారయ్య', 'Bangarayya'],
  ['ఎరకయ', 'ఎరకయ్య', 'Yerakayya'],
  ['బోడె య', 'బోడయ్య', 'Bodayya'],
  
  // Kalagati family
  ['కలగాటి తి రూ పతిరా వ', 'తిరుపతిరావు కలగాటి', 'Thirupathi Rao Kalagati'],
  ['తి రూ పతిరా వ', 'తిరుపతిరావు', 'Thirupathi Rao'],
  ['చం ద య కలగాట', 'చంద్రయ్య కలగాటి', 'Chandrayya Kalagati'],
  ['చం ద య', 'చంద్రయ్య', 'Chandrayya'],
  ['చంద దయ', 'చంద్రయ్య', 'Chandrayya'],
  ['క రెమ కలగాట', 'కర్రెమ్మ కలగాటి', 'Karremma Kalagati'],
  ['క రెమ', 'కర్రెమ్మ', 'Karremma'],
  ['కలగాటి', 'కలగాటి', 'Kalagati'],
  ['కలగాట', 'కలగాటి', 'Kalagati'],

  // Penuguduru family
  ['కాళిదా సు పె నుగుదురు', 'కాళిదాసు పెనుగుదురు', 'Kalidasu Penuguduru'],
  ['కాళిదా సు', 'కాళిదాసు', 'Kalidasu'],
  ['కాళిదాసు', 'కాళిదాసు', 'Kalidasu'],
  ['రా మలక్షి పె నుగుదురు', 'రామలక్ష్మి పెనుగుదురు', 'Ramalakshmi Penuguduru'],
  ['రా మలక్షి', 'రామలక్ష్మి', 'Ramalakshmi'],
  ['రామలక్ష్మి', 'రామలక్ష్మి', 'Ramalakshmi'],
  ['పే నుగుదురూ వే ంకటరమన', 'వెంకటరమణ పెనుగుదురు', 'Venkataramana Penuguduru'],
  ['వే ంకటరమన', 'వెంకటరమణ', 'Venkataramana'],
  ['పే నుగుదురూ సి ంహా ది', 'సింహాద్రి పెనుగుదురు', 'Simhadri Penuguduru'],
  ['సి ంహా ది', 'సింహాద్రి', 'Simhadri'],
  ['సింహాద్రి', 'సింహాద్రి', 'Simhadri'],
  ['యమున దా సరి', 'యమున దాసరి', 'Yamuna Dasari'],
  ['యమున', 'యమున', 'Yamuna'],
  ['దా సరి', 'దాసరి', 'Dasari'],
  ['దాసరి', 'దాసరి', 'Dasari'],
  ['పెనుగుదురు', 'పెనుగుదురు', 'Penuguduru'],
  ['పె నుగూ దురు', 'పెనుగుదురు', 'Penuguduru'],
  ['పే నుగుదురూ', 'పెనుగుదురు', 'Penuguduru'],
  ['వె ంకులు', 'వెంకులు', 'Venkulu'],
  ['కాలిదా సూ', 'కాళిదాసు', 'Kalidasu'],

  // Velamala family
  ['భా స రరా వు వె లమల', 'భాస్కరరావు వెలమల', 'Bhaskara Rao Velamala'],
  ['భా స రరా వు', 'భాస్కరరావు', 'Bhaskara Rao'],
  ['భాస్కరరావు', 'భాస్కరరావు', 'Bhaskara Rao'],
  ['లక్షి వె లమల', 'లక్ష్మి వెలమల', 'Lakshmi Velamala'],
  ['మన ధరా వు వె లమల', 'మన్మధరావు వెలమల', 'Manmadha Rao Velamala'],
  ['మన ధరా వు', 'మన్మధరావు', 'Manmadha Rao'],
  ['మన్మధరావు', 'మన్మధరావు', 'Manmadha Rao'],
  ['అశి ని వె లమల', 'అశ్విని వెలమల', 'Aswini Velamala'],
  ['అశి ని', 'అశ్విని', 'Aswini'],
  ['అశ్విని', 'అశ్విని', 'Aswini'],
  ['వె లమల', 'వెలమల', 'Velamala'],
  ['వెలమల', 'వెలమల', 'Velamala'],
  ['అప య', 'అప్పయ్య', 'Appayya'],
  ['అప లసా మి', 'అప్పలస్వామి', 'Appalaswami'],
  ['లక్ష ణరా వు', 'లక్ష్మణరావు', 'Lakshmana Rao'],
  ['సూరమ', 'సూరమ్మ', 'Sooramma'],
  ['వా సుదే వరా వు', 'వాసుదేవరావు', 'Vasudeva Rao'],
  ['యశొ ద', 'యశోద', 'Yashoda'],
  ['సాయ్ రా జు', 'సాయీరాజు', 'Sayiraju'],

  // Jammana family
  ['చా మంతి జమా నా', 'చామంతి జమ్మాన', 'Chamanthi Jammana'],
  ['చా మంతి', 'చామంతి', 'Chamanthi'],
  ['సురేష్ జమా నా', 'సురేష్ జమ్మాన', 'Suresh Jammana'],
  ['సురేష్', 'సురేష్', 'Suresh'],
  ['జమా నా', 'జమ్మాన', 'Jammana'],
  ['జమ్మాన', 'జమ్మాన', 'Jammana'],
  ['బమి డి గౌ తమి', 'గౌతమి బమ్మిడి', 'Gowthami Bammidi'],
  ['గౌ తమి', 'గౌతమి', 'Gowthami'],
  ['బమి డి', 'బమ్మిడి', 'Bammidi'],
  ['బమ్మిడి', 'బమ్మిడి', 'Bammidi'],

  // Yendu / Endu
  ['జయలక్షి ఎండు', 'జయలక్ష్మి ఎండ', 'Jayalakshmi Yenda'],
  [' ందూ కే శవ రా వు', 'కేశవరావు ఎండ', 'Kesava Rao Yenda'],
  ['కే శవ రా వు', 'కేశవరావు', 'Kesava Rao'],
  ['సాయి లక్షి యె ండు', 'సాయిలక్ష్మి ఎండ', 'Sai Lakshmi Yenda'],
  ['సాయి లక్షి', 'సాయిలక్ష్మి', 'Sai Lakshmi'],
  ['పవని యండ', 'పావని ఎండ', 'Pavani Yenda'],
  ['ఎ రం నా యుడు', 'యర్రంనాయుడు', 'Yerramnaidu'],
  ['జగా రా వు', 'జగ్గారావు', 'Jaggarao'],
  ['జగా రా వు', 'జగ్గారావు', 'Jaggarao'],
  ['ఎండు', 'ఎండ', 'Yenda'],
  ['యె ండు', 'ఎండ', 'Yenda'],
  ['యండ', 'ఎండ', 'Yenda'],
  ['ఎండ', 'ఎండ', 'Yenda'],

  // Kunchala & Madi
  ['కుంచాల', 'కుంచాల', 'Kunchala'],
  ['అమరా వతి కుంచాల', 'అమరావతి కుంచాల', 'Amaravathi Kunchala'],
  ['అమరా వతి', 'అమరావతి', 'Amaravathi'],
  ['మడి', 'మడ్డి', 'Maddi'],
  ['మడ్డి', 'మడ్డి', 'Maddi'],
  ['జో స మడి', 'జ్యోత్స్న మడ్డి', 'Jyothsna Maddi'],
  ['శా మల రా వు మడి', 'శ్యామలరావు మడ్డి', 'Syamala Rao Maddi'],
  ['శా మల రా వు', 'శ్యామలరావు', 'Syamala Rao'],
  ['శ్యామలరావు', 'శ్యామలరావు', 'Syamala Rao'],
  ['ముఖలింగం', 'ముఖలింగం', 'Mukhalingam'],
  ['చి లకయ', 'చిలకయ్య', 'Chilakayya']
];

function cleanTelugu(str) {
  if (!str) return '';
  // 1. Remove all English characters and digits
  let s = str.replace(/[A-Za-z0-9]/g, '');
  // normalize spaces
  s = s.replace(/[\t\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();

  // match known phrases in dictionary
  for (const [raw, te, en] of dictionary) {
    if (s.includes(raw)) {
      s = s.replace(new RegExp(raw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), te);
    }
  }

  // Remove any non-Telugu characters (Telugu unicode is 0C00-0C7F)
  s = s.replace(/[^\u0C00-\u0C7F\s]/g, '').replace(/\s+/g, ' ').trim();
  return s;
}

function cleanEnglish(rawStr, teluguStr) {
  // Check exact whole matches in dictionary
  for (const [raw, te, en] of dictionary) {
    if (rawStr === raw || teluguStr === te) {
      return en;
    }
  }

  // Check substring replacements from dictionary
  let workingTe = teluguStr;
  let translatedWords = [];

  for (const [raw, te, en] of dictionary) {
    if (workingTe.includes(te)) {
      workingTe = workingTe.replace(new RegExp(te, 'g'), ' ' + en + ' ');
    }
  }

  // Transliterate remaining Telugu characters
  const teCharMap = {
    'అ': 'A', 'ఆ': 'Aa', 'ఇ': 'I', 'ఈ': 'Ee', 'ఉ': 'U', 'ఊ': 'Oo', 'ఋ': 'Ru',
    'ఎ': 'E', 'ఏ': 'E', 'ఐ': 'Ai', 'ఒ': 'O', 'ఓ': 'O', 'ఔ': 'Au', 'అం': 'Am',
    'క': 'Ka', 'ఖ': 'Kha', 'గ': 'Ga', 'ఘ': 'Gha',
    'చ': 'Cha', 'ఛ': 'Chha', 'జ': 'Ja', 'ఝ': 'Jha',
    'ట': 'Ta', 'ఠ': 'Tha', 'డ': 'Da', 'ఢ': 'Dha', 'ణ': 'Na',
    'త': 'Tha', 'థ': 'Thha', 'ద': 'Da', 'ధ': 'Dha', 'న': 'Na',
    'ప': 'Pa', 'ఫ': 'Pha', 'బ': 'Ba', 'భ': 'Bha', 'మ': 'Ma',
    'య': 'Ya', 'ర': 'Ra', 'ల': 'La', 'వ': 'Va', 'శ': 'Sa',
    'ష': 'Sha', 'స': 'Sa', 'హ': 'Ha', 'ళ': 'La', 'క్ష': 'Ksha', 'ఱ': 'Ra',
    'ా': 'a', 'ి': 'i', 'ీ': 'ee', 'ు': 'u', 'ూ': 'oo', 'ృ': 'ru',
    'ె': 'e', 'ే': 'e', 'ై': 'ai', 'ొ': 'o', 'ో': 'o', 'ౌ': 'au',
    '్': '', 'ం': 'm', 'ః': 'h'
  };

  let out = '';
  for (let ch of workingTe) {
    if (teCharMap[ch] !== undefined) {
      out += teCharMap[ch];
    } else if (/[A-Za-z\s]/.test(ch)) {
      out += ch;
    }
  }

  // Strictly remove all non-English characters
  out = out.replace(/[^A-Za-z\s]/g, '').replace(/\s+/g, ' ').trim();
  return out.split(' ').filter(Boolean).map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join(' ');
}

async function run() {
  const buf = fs.readFileSync('public/assets/aistudio/voters_ff3cd669.pdf');
  const parser = new PDFParse(new Uint8Array(buf));
  const res = await parser.getText();
  
  let voters = [];
  let currentWard = '1';

  for (let pNum = 2; pNum < res.pages.length - 1; pNum++) {
    const raw = res.pages[pNum].text.replace(/\x00/g, '');
    const wardM = raw.match(/వా\s*రు\s*నె\s*౦బరు\s*:\s*(\d+)/) || raw.match(/వా\s*ర్డు\s*:\s*(\d+)/);
    if (wardM) currentWard = wardM[1];

    const blocks = raw.split(/గా\s*\.\s*పం\s*\.\s*ఓ\s*\.\s*జా\s*\.\s*వ\s*\.\s*సం\s*\.\s*/);
    for (let i = 1; i < blocks.length; i++) {
      const b = blocks[i];
      const epicM = b.match(/([A-Z]{3}[0-9]{7})/);
      if (!epicM) continue;
      const epic = epicM[1];

      const lines = b.split('\n').map(l => l.trim()).filter(Boolean);
      const slM = lines[0].match(/^(\d+)/);
      const slNo = slM ? parseInt(slM[1]) : voters.length + 1;

      let rawName = '';
      let rawRel = '';
      let relType = 'Father';
      let age = 35;
      let gender = 'Male';
      let houseNo = '1-1';

      for (let rawLine of lines) {
        let line = rawLine.replace(/[\t\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
        if (line.includes('ఓటరు పే రు') || line.includes('ఓటరు పేరు')) {
          const parts = line.split(':');
          if (parts.length > 1) rawName = parts.slice(1).join(':').trim();
        } else if (line.includes('పే రు') || line.includes('పేరు')) {
          if (line.includes('భర')) {
            relType = 'Husband';
          } else if (line.includes('తం') || line.includes('తండ్రి') || line.includes('తండి')) {
            relType = 'Father';
          } else if (line.includes('తలి') || line.includes('తల్లి')) {
            relType = 'Mother';
          } else {
            relType = 'Other';
          }
          const parts = line.split(':');
          if (parts.length > 1) rawRel = parts.slice(1).join(':').trim();
        } else if (line.includes('వయసు')) {
          const ageM = line.match(/వయసు\s*:\s*(\d+)/);
          if (ageM) age = parseInt(ageM[1]);
          if (line.includes('లి') && line.includes('పు')) {
            gender = 'Male';
          } else {
            gender = 'Female';
          }
        } else if (line.includes('ఇంటి సంఖ')) {
          const houseM = line.match(/ఇంటి\s*సంఖ\s*:\s*([^\s]+)/);
          if (houseM) houseNo = houseM[1].trim();
        }
      }

      // Clean Telugu (100% PURE TELUGU, NO ENGLISH)
      const nameTe = cleanTelugu(rawName);
      const relNameTe = cleanTelugu(rawRel);

      // Clean English (100% PURE ENGLISH, NO TELUGU)
      const nameEn = cleanEnglish(rawName, nameTe);
      const relNameEn = cleanEnglish(rawRel, relNameTe);

      // Photo check in root photos directory
      let photoUrl = '';
      if (fs.existsSync('photos/' + epic + '.jpg')) {
        photoUrl = 'photos/' + epic + '.jpg';
      } else if (fs.existsSync('photos/sl_' + slNo + '.jpg')) {
        photoUrl = 'photos/sl_' + slNo + '.jpg';
      } else {
        photoUrl = 'photos/' + epic + '.jpg';
      }

      voters.push({
        id: slNo,
        sl_no: slNo,
        epic,
        name: nameTe,
        name_te: nameTe,
        name_en: nameEn,
        nameEn: nameEn,
        relation_type: relType,
        relation_name: relNameTe,
        guardian_name_te: relNameTe,
        relation_name_en: relNameEn,
        guardian_name_en: relNameEn,
        house_no: houseNo,
        age,
        gender,
        ward_no: currentWard,
        section_name: 'కొమ్మువలస - వార్డు ' + currentWard,
        photo_url: photoUrl,
        is_voted: 0,
        flag_color: 'none'
      });
    }
  }

  console.log('Total extracted:', voters.length);
  const chenchalaList = voters.filter(v => 
    v.name_te.includes('చెంచ') || 
    v.name_en.toLowerCase().includes('chench') || 
    v.relation_name.includes('చెంచ') || 
    v.relation_name_en.toLowerCase().includes('chench')
  );
  console.log('Total Chenchala voters in clean extraction:', chenchalaList.length);
  chenchalaList.forEach(c => {
    console.log(`Sl: ${c.sl_no} | EPIC: ${c.epic} | Te: '${c.name_te}' | En: '${c.name_en}' | Rel: '${c.relation_type}' | RelTe: '${c.relation_name}' | RelEn: '${c.relation_name_en}'`);
  });

  // Verify purity of languages
  let mixedInTe = voters.filter(v => /[A-Za-z]/.test(v.name_te) || /[A-Za-z]/.test(v.relation_name));
  let mixedInEn = voters.filter(v => /[\u0C00-\u0C7F]/.test(v.name_en) || /[\u0C00-\u0C7F]/.test(v.relation_name_en));
  console.log('Voters with English in Telugu column:', mixedInTe.length);
  console.log('Voters with Telugu in English column:', mixedInEn.length);

  // Write out to all 3 paths
  fs.writeFileSync('data/kommuvalasa_voters.json', JSON.stringify(voters, null, 2), 'utf8');
  fs.writeFileSync('uploads/Andhra_Pradesh/Srikakulam/Patapatnam/L.N._Peta/Kommuvalasa/voters.json', JSON.stringify(voters, null, 2), 'utf8');
  fs.writeFileSync('public/voters_data.json', JSON.stringify(voters, null, 2), 'utf8');
  console.log('Successfully saved verified clean voters.json to all targets!');
}

run().catch(console.error);
